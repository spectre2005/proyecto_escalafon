<?php

namespace Tests\Feature;

use App\Models\ArchivoDocumento;
use App\Models\DocumentoUsuario;
use App\Models\Legajo;
use App\Models\RequerimientoDocumento;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use Database\Seeders\SeccionesLegajoCatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RequerimientosAutomaticosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SeccionesLegajoCatalogoSeeder::class);
        TipoDocumento::query()->update(['activo' => false]);
        $this->actuarComo();
    }

    private function tipo(array $datos = []): TipoDocumento
    {
        return TipoDocumento::factory()->create($datos + ['seccion_legajo_id' => 3, 'obligatorio' => true, 'activo' => true,
            'aplica_carrera' => true, 'aplica_plazo_determinado' => true, 'aplica_designado' => true]);
    }

    private function legajo(): Legajo
    {
        return Legajo::factory()->create(['estado_legajo' => 'activo', 'usuario_id' => Usuario::factory()->create(['exige_colegiatura' => null])->id]);
    }

    public function test_generates_confirmed_missing_requirements_once_and_keeps_unknown_conditions_separate(): void
    {
        $legajo = $this->legajo();
        $exigido = $this->tipo(['nombre' => 'Constancia obligatoria']);
        $incierto = $this->tipo(['nombre' => 'Colegiatura por determinar', 'requiere_colegiatura' => true]);
        $this->tipo(['nombre' => 'Documento opcional', 'obligatorio' => false]);
        $this->tipo(['nombre' => 'Documento desactivado', 'activo' => false]);
        $this->postJson('/api/v1/solicitudes-legajo/sincronizar')->assertOk()->assertJsonPath('creados', 1)->assertJsonPath('plazo_dias', 7);
        $this->assertDatabaseHas('requerimientos_documentos', ['usuario_id' => $legajo->usuario_id, 'tipo_documento_id' => $exigido->id, 'origen' => 'automatico', 'estado' => 'abierto']);
        $this->assertDatabaseMissing('requerimientos_documentos', ['tipo_documento_id' => $incierto->id]);
        $this->postJson('/api/v1/solicitudes-legajo/sincronizar')->assertOk()->assertJsonPath('creados', 0);
        $this->assertDatabaseCount('notificaciones_usuario', 1);
        $this->getJson('/api/v1/solicitudes-legajo/sugerencias')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.estado_codigo', 'por_determinar');
    }

    public function test_generates_observed_and_expired_documents_without_incorporating_new_documents(): void
    {
        $legajo = $this->legajo();
        foreach (['observado', 'aceptado'] as $estado) {
            $tipo = $this->tipo(['nombre' => 'Constancia '.$estado]);
            DocumentoUsuario::factory()->create(['usuario_id' => $legajo->usuario_id, 'legajo_id' => $legajo->id,
                'tipo_documento_id' => $tipo->id, 'seccion_legajo_id' => 3, 'estado' => $estado,
                'archivo_id' => ArchivoDocumento::factory()->create()->id,
                'fecha_caducidad' => $estado === 'aceptado' ? now('America/Lima')->subDay()->toDateString() : null]);
        }
        $this->postJson('/api/v1/solicitudes-legajo/sincronizar')->assertOk()->assertJsonPath('creados', 2);
        $this->assertDatabaseCount('documentos_usuario', 2);
        $motivos = RequerimientoDocumento::all()->map(fn ($r) => $r->seguimiento[0]['motivo'])->sort()->values()->all();
        $this->assertSame(['observado', 'vencido'], $motivos);
    }

    public function test_canceled_automatic_requirements_are_not_recreated_for_the_same_cause(): void
    {
        $this->legajo();
        $this->tipo();
        $this->postJson('/api/v1/solicitudes-legajo/sincronizar')->assertOk()->assertJsonPath('creados', 1);
        $id = RequerimientoDocumento::sole()->id;
        $this->postJson('/api/v1/solicitudes-legajo/'.$id.'/gestionar', ['accion' => 'cancelar', 'mensaje' => 'No se requiere solicitar de nuevo'])->assertOk();
        $this->postJson('/api/v1/solicitudes-legajo/sincronizar')->assertOk()->assertJsonPath('creados', 0);
        $this->assertDatabaseCount('requerimientos_documentos', 1);
    }

    public function test_closed_legajos_and_existing_manual_requests_are_not_duplicated(): void
    {
        $abierto = $this->legajo();
        $cerrado = $this->legajo();
        $cerrado->update(['estado_legajo' => 'cerrado']);
        $tipo = $this->tipo();
        RequerimientoDocumento::factory()->create(['usuario_id' => $abierto->usuario_id, 'tipo_documento_id' => $tipo->id,
            'clase' => 'documentos', 'estado' => 'abierto']);
        $this->postJson('/api/v1/solicitudes-legajo/sincronizar')->assertOk()->assertJsonPath('creados', 0);
        $this->assertDatabaseCount('requerimientos_documentos', 1);
    }

    public function test_daily_scheduler_generates_requirements_without_opening_the_panel_and_teacher_cannot_scan_others(): void
    {
        $this->legajo();
        $this->tipo();
        $this->artisan('legajo:recordar-solicitudes')->assertSuccessful();
        $this->assertDatabaseCount('requerimientos_documentos', 1);
        $docente = $this->actuarComo(codigo: 'autoservicio_legajo');
        Sanctum::actingAs($docente);
        $this->postJson('/api/v1/solicitudes-legajo/sincronizar')->assertForbidden();
    }
}
