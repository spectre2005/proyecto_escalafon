<?php

namespace Tests\Feature;

use App\Models\Feriado;
use App\Models\Legajo;
use App\Models\RequerimientoDocumento;
use App\Models\Usuario;
use App\Support\DiasHabiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Plazos de los requerimientos en días hábiles (Directiva, arts. 4.3 y 5.1). Fechas fijas para no depender del día. */
class PlazosRequerimientoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Viernes 20/11/2026 a mediodía en Lima.
        $this->travelTo(Carbon::parse('2026-11-20 12:00:00', 'America/Lima'));
    }

    private function titular(): Usuario
    {
        $u = Usuario::factory()->create();
        Legajo::factory()->create(['usuario_id' => $u->id, 'estado_legajo' => 'activo']);

        return $u;
    }

    private function crear(Usuario $u, array $datos = [])
    {
        return $this->postJson('/api/v1/solicitudes-legajo', ['clase' => 'documentos', 'usuario_id' => $u->id, 'descripcion' => 'Presentar documentos', ...$datos]);
    }

    public function test_business_days_skip_weekends_and_registered_holidays(): void
    {
        // Desde el viernes: lunes 23 a viernes 27 son los 5 días hábiles.
        $this->assertSame('2026-11-27', DiasHabiles::sumar(5)->toDateString());
        Feriado::query()->create(['fecha' => '2026-11-25', 'descripcion' => 'Feriado de prueba']);
        $this->assertSame('2026-11-30', DiasHabiles::sumar(5)->toDateString());
        $this->assertSame(4, DiasHabiles::entre('2026-11-20', '2026-11-27'));
        $this->assertSame(0, DiasHabiles::entre('2026-11-20', '2026-11-20'));
    }

    public function test_documents_deadline_is_at_most_five_business_days_and_defaults_to_it(): void
    {
        $this->actuarComo();
        $u = $this->titular();
        $this->getJson('/api/v1/solicitudes-legajo/opciones')->assertOk()
            ->assertJsonPath('plazos.documentos', '2026-11-27')->assertJsonPath('plazos.actualizacion', '2027-01-01');
        $this->crear($u, ['fecha_limite' => '2026-11-30'])->assertUnprocessable()->assertJsonValidationErrors('fecha_limite');
        $id = $this->crear($u)->assertCreated()->json('data.id');
        $registro = RequerimientoDocumento::query()->findOrFail($id);
        $this->assertSame('2026-11-27', $registro->fecha_limite->toDateString());
        $this->assertSame(5, $registro->plazo_dias_habiles);
    }

    public function test_update_requests_default_to_thirty_business_days_but_the_orh_may_set_another(): void
    {
        $this->actuarComo();
        $u = $this->titular();
        $id = $this->crear($u, ['clase' => 'actualizacion', 'descripcion' => 'Actualizar domicilio'])->assertCreated()->json('data.id');
        $this->assertSame(DiasHabiles::sumar(30)->toDateString(), RequerimientoDocumento::query()->findOrFail($id)->fecha_limite->toDateString());
        $this->crear($u, ['clase' => 'actualizacion', 'descripcion' => 'Actualizar estado civil', 'fecha_limite' => '2027-03-01'])->assertCreated();
    }

    public function test_extension_is_at_most_five_business_days_after_the_original_deadline(): void
    {
        $this->actuarComo();
        $u = $this->titular();
        $id = $this->crear($u)->assertCreated()->json('data.id');
        $this->assertSame('2026-12-04', collect($this->getJson('/api/v1/solicitudes-legajo')->assertOk()->json('data'))->firstWhere('id', $id)['prorroga_maxima']);
        $prorroga = fn (string $fecha) => $this->postJson('/api/v1/solicitudes-legajo/'.$id.'/gestionar', ['accion' => 'prorrogar', 'mensaje' => 'Nuevo plazo', 'prorroga_hasta' => $fecha]);
        $prorroga('2026-12-07')->assertUnprocessable();
        $prorroga('2026-12-04')->assertOk();
        $this->assertSame('2026-12-04', RequerimientoDocumento::query()->findOrFail($id)->prorroga_hasta->toDateString());
    }
}
