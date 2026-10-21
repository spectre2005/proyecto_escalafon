<?php

namespace Tests\Feature;

use App\Models\Legajo;
use App\Models\PerfilAdministrativo;
use App\Models\PerfilDocente;
use App\Models\RegimenNormativo;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Coherencia del vínculo laboral en la sección 02: la ley corresponde al tipo de personal (la misma regla del alta,
 * RegimenesLaborales::error) y el tipo de personal no cambia si el vínculo ya tiene datos del puesto.
 */
class VinculoCoherenciaTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $persona;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actuarComo();
        $this->persona = Usuario::factory()->create();
        Legajo::factory()->create(['usuario_id' => $this->persona->id]);
    }

    private function ley(string $tipo, bool $activo = true): RegimenNormativo
    {
        return RegimenNormativo::factory()->create(['aplica_a' => $tipo, 'activo' => $activo]);
    }

    private function url(string $vinculo = ''): string
    {
        return '/api/v1/expedientes/'.$this->persona->id.'/vinculos-laborales'.($vinculo === '' ? '' : '/'.$vinculo);
    }

    private function vinculo(array $cambios = []): array
    {
        return ['modalidad_ingreso' => 'otro_sustentado', 'sustento_ingreso' => 'Mandato judicial de prueba.', 'numero_contrato' => 'C-001', 'fecha_contrato' => '2025-01-30', 'tipo_personal' => 'administrativo', 'regimen_laboral' => 'plazo_determinado', 'nombre_puesto' => 'Especialista', 'fecha_inicio' => '2025-02-01', ...$cambios];
    }

    public function test_a_law_of_another_staff_type_is_rejected_when_registering(): void
    {
        $this->postJson($this->url(), $this->vinculo(['regimen_normativo_id' => $this->ley('docente')->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('regimen_normativo_id');
        $this->postJson($this->url(), $this->vinculo(['regimen_normativo_id' => $this->ley('administrativo', activo: false)->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('regimen_normativo_id');
        $this->assertDatabaseCount('vinculos_laborales', 0);

        $this->postJson($this->url(), $this->vinculo(['regimen_normativo_id' => $this->ley('administrativo')->id]))->assertCreated();
    }

    public function test_a_law_of_another_staff_type_is_rejected_when_correcting(): void
    {
        $ley = $this->ley('administrativo');
        $id = $this->postJson($this->url(), $this->vinculo(['regimen_normativo_id' => $ley->id]))->assertCreated()->json('data.id');

        $this->patchJson($this->url($id), ['regimen_normativo_id' => $this->ley('docente')->id])
            ->assertUnprocessable()->assertJsonValidationErrors('regimen_normativo_id');
        // Sin datos del puesto el tipo se puede corregir, pero no deja la ley de otro tipo.
        $this->patchJson($this->url($id), ['tipo_personal' => 'docente'])->assertUnprocessable()->assertJsonValidationErrors('regimen_normativo_id');
        $this->assertDatabaseHas('vinculos_laborales', ['id' => $id, 'tipo_personal' => 'administrativo', 'regimen_normativo_id' => $ley->id]);

        $leyDocente = $this->ley('docente')->id;
        $this->patchJson($this->url($id), ['tipo_personal' => 'docente', 'regimen_normativo_id' => $leyDocente])->assertUnprocessable()->assertJsonValidationErrors('numero_resolucion');
        $this->patchJson($this->url($id), ['tipo_personal' => 'docente', 'regimen_normativo_id' => $leyDocente, 'numero_resolucion' => 'R-DOC-001', 'fecha_resolucion' => '2025-01-30'])->assertOk();
    }

    public function test_an_unchanged_inactive_law_does_not_block_other_corrections(): void
    {
        $ley = $this->ley('administrativo');
        $id = $this->postJson($this->url(), $this->vinculo(['regimen_normativo_id' => $ley->id]))->assertCreated()->json('data.id');
        $ley->update(['activo' => false]);

        $this->patchJson($this->url($id), ['nombre_puesto' => 'Especialista II'])->assertOk()->assertJsonPath('data.nombre_puesto', 'Especialista II');
    }

    public function test_staff_type_cannot_change_when_the_link_has_position_data_and_the_profile_is_kept(): void
    {
        $docente = VinculoLaboral::factory()->create(['usuario_id' => $this->persona->id, 'tipo_personal' => 'docente',
            'regimen_normativo_id' => $this->ley('docente')->id, 'fecha_cese' => null, 'anulado' => false]);
        $perfil = PerfilDocente::query()->create(['vinculo_laboral_id' => $docente->id, 'condicion_docente' => 'ordinario', 'categoria' => 'principal']);

        $this->patchJson($this->url($docente->id), ['tipo_personal' => 'administrativo', 'regimen_normativo_id' => $this->ley('administrativo')->id])
            ->assertUnprocessable()->assertJsonValidationErrors('tipo_personal');
        $this->assertDatabaseHas('vinculos_laborales', ['id' => $docente->id, 'tipo_personal' => 'docente', 'regimen_normativo_id' => $docente->regimen_normativo_id]);
        $this->assertDatabaseHas('perfil_docente', ['id' => $perfil->id, 'vinculo_laboral_id' => $docente->id, 'condicion_docente' => 'ordinario', 'categoria' => 'principal']);

        $administrativo = VinculoLaboral::factory()->create(['usuario_id' => $this->persona->id, 'tipo_personal' => 'administrativo',
            'regimen_normativo_id' => $this->ley('administrativo')->id, 'fecha_cese' => null, 'anulado' => false]);
        PerfilAdministrativo::query()->create(['vinculo_laboral_id' => $administrativo->id, 'grupo_ocupacional' => 'tecnico']);
        $this->patchJson($this->url($administrativo->id), ['tipo_personal' => 'docente', 'regimen_normativo_id' => $this->ley('docente')->id])
            ->assertUnprocessable()->assertJsonValidationErrors('tipo_personal');
        $this->assertDatabaseHas('perfil_administrativo', ['vinculo_laboral_id' => $administrativo->id, 'grupo_ocupacional' => 'tecnico']);

        // El mismo tipo y los demás datos se siguen corrigiendo.
        $this->patchJson($this->url($docente->id), ['tipo_personal' => 'docente', 'nombre_puesto' => 'Profesor principal'])->assertOk();
        // Ningún perfil queda colgado de un vínculo de otro tipo.
        $this->assertSame(0, PerfilDocente::query()->whereHas('vinculoLaboral', fn ($q) => $q->where('tipo_personal', '!=', 'docente'))->count());
        $this->assertSame(0, PerfilAdministrativo::query()->whereHas('vinculoLaboral', fn ($q) => $q->where('tipo_personal', '!=', 'administrativo'))->count());
    }
}
