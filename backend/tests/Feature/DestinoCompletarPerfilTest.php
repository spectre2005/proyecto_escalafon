<?php

namespace Tests\Feature;

use App\Models\Familiar;
use App\Models\Legajo;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use App\Support\Seccion01;
use Database\Seeders\SeccionesLegajoCatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DestinoCompletarPerfilTest extends TestCase
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
        return TipoDocumento::factory()->create($datos + ['seccion_legajo_id' => 3, 'activo' => true, 'obligatorio' => true,
            'aplica_carrera' => true, 'aplica_plazo_determinado' => true, 'aplica_designado' => true]);
    }

    private function persona(array $datos = []): Usuario
    {
        $u = Usuario::factory()->create($datos);
        Legajo::factory()->create(['usuario_id' => $u->id, 'estado_legajo' => 'activo']);

        return $u;
    }

    private function destino(TipoDocumento $tipo): string
    {
        $filas = $this->getJson('/api/v1/solicitudes-legajo/sugerencias')->assertOk()->json('data');

        return collect($filas)->firstWhere('tipo_documento_id', $tipo->id)['destino_revision'];
    }

    public function test_links_to_the_pending_question_in_the_exact_employment_record(): void
    {
        $u = $this->persona();
        $tipo = $this->tipo(['requiere_colegiatura' => true]);
        $v = VinculoLaboral::factory()->create(['usuario_id' => $u->id, 'anulado' => false, 'fecha_cese' => null,
            'exige_colegiatura' => null, 'regimen_laboral' => 'carrera']);
        $this->assertSame('/legajos/'.$u->id.'?categoria=s02-vinculo#perfil-pendiente-exige_colegiatura-'.$v->id, $this->destino($tipo));
    }

    public function test_links_to_personal_conditions_and_to_creation_of_a_missing_employment_record(): void
    {
        $u = $this->persona(['tiene_derechohabientes' => null]);
        $familia = $this->tipo(['requiere_derechohabientes' => true]);
        $regimen = $this->tipo(['aplica_plazo_determinado' => false, 'aplica_designado' => false]);
        $this->assertSame('/legajos/'.$u->id.'?categoria=s01-familia#perfil-pendiente-tiene_derechohabientes-persona', $this->destino($familia));
        $this->assertSame('/legajos/'.$u->id.'?categoria=s02-vinculo#bloque-registros', $this->destino($regimen));
    }

    public function test_links_to_the_relative_whose_birth_date_is_missing(): void
    {
        $u = $this->persona(['tiene_derechohabientes' => true]);
        $tipo = $this->tipo(['seccion_legajo_id' => 1, 'codigo' => Seccion01::DNI_HIJOS]);
        $f = Familiar::factory()->create(['usuario_id' => $u->id, 'parentesco' => 'hijo', 'anulado' => false, 'fecha_nacimiento' => null]);
        $this->assertSame('/legajos/'.$u->id.'?categoria=s01-familia#registro-'.$f->id, $this->destino($tipo));
    }
}
