<?php

namespace Tests\Feature;

use App\Models\Induccion;
use App\Models\Legajo;
use App\Models\PerfilAdministrativo;
use App\Models\PerfilDocente;
use App\Models\PeriodoPrueba;
use App\Models\RegimenNormativo;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use Database\Seeders\Seccion02CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class Seccion02ApiTest extends TestCase
{
    use RefreshDatabase;

    private function url(Usuario $persona, string $ruta = ''): string
    {
        return '/api/v1/expedientes/'.$persona->id.($ruta === '' ? '' : '/'.$ruta);
    }

    private function vinculoValido(array $cambios = []): array
    {
        return [...[
            'tipo_personal' => 'administrativo', 'regimen_laboral' => 'plazo_determinado',
            'regimen_normativo_id' => RegimenNormativo::factory()->create()->id, 'nombre_puesto' => 'Especialista',
            'tiene_periodo_prueba' => true, 'proceso_seleccion' => 'CAS 001-2025', 'fecha_resultado_seleccion' => '2025-01-20',
            'numero_documento_vinculo' => 'CONTRATO 010-2025', 'fecha_inicio' => '2025-02-01', 'fecha_fin_prevista' => '2025-12-31',
        ], ...$cambios];
    }

    public function test_section_lists_four_subsections_with_document_state_and_requirement(): void
    {
        $this->actuarComo();
        $this->seed(Seccion02CatalogoSeeder::class);
        $persona = Usuario::factory()->create(['tiene_periodo_prueba' => false]);
        VinculoLaboral::factory()->create(['usuario_id' => $persona->id, 'regimen_laboral' => 'designado']);

        $response = $this->getJson($this->url($persona, 'seccion-02'))->assertOk()
            ->assertJsonPath('data.legajo', null)->assertJsonPath('data.tiene_periodo_prueba', false)
            ->assertJsonCount(4, 'data.subsecciones')->assertJsonCount(2, 'data.subsecciones.0.tipos')
            ->assertJsonCount(5, 'data.subsecciones.1.tipos')->assertJsonCount(5, 'data.subsecciones.2.tipos')
            ->assertJsonPath('data.subsecciones.0.tipos.0.literal', 'a')->assertJsonPath('data.subsecciones.0.tipos.0.estado', 'pendiente')
            ->assertJsonPath('data.subsecciones.0.tipos.0.exigido', true)
            // d) es opcional; m) depende de tiene_periodo_prueba.
            ->assertJsonPath('data.subsecciones.1.tipos.1.exigido', false)
            ->assertJsonPath('data.subsecciones.3.tipos.0.literal', 'm')->assertJsonPath('data.subsecciones.3.tipos.0.exigido', false);
        $this->assertSame(['designado'], $response->json('data.perfil.regimenes_clasificados'));
        $this->getJson($this->url($persona))->assertOk()->assertJsonPath('data.tiene_periodo_prueba', false);
    }

    public function test_forms_require_an_open_legajo(): void
    {
        $this->actuarComo();
        $persona = Usuario::factory()->create();

        $this->postJson($this->url($persona, 'vinculos-laborales'), $this->vinculoValido())->assertConflict();
        Legajo::factory()->create(['usuario_id' => $persona->id, 'estado_legajo' => 'pasivo']);
        $this->postJson($this->url($persona, 'vinculos-laborales'), $this->vinculoValido())->assertConflict();
        $this->assertDatabaseCount('vinculos_laborales', 0);
    }

    public function test_link_is_registered_with_selection_data_and_only_corrected_while_open(): void
    {
        $this->actuarComo();
        $persona = Usuario::factory()->create();
        Legajo::factory()->create(['usuario_id' => $persona->id]);
        $url = $this->url($persona, 'vinculos-laborales');

        $this->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors(['tipo_personal', 'regimen_laboral', 'regimen_normativo_id', 'nombre_puesto', 'fecha_inicio']);
        $this->postJson($url, $this->vinculoValido(['regimen_laboral' => '276']))->assertUnprocessable()->assertJsonValidationErrors('regimen_laboral');
        $this->postJson($url, $this->vinculoValido(['fecha_fin_prevista' => '2024-01-01']))->assertUnprocessable()->assertJsonValidationErrors('fecha_fin_prevista');
        $this->postJson($url, $this->vinculoValido(['usuario_id' => Usuario::factory()->create()->id]))->assertUnprocessable()->assertJsonValidationErrors('usuario_id');
        $id = $this->postJson($url, $this->vinculoValido())->assertCreated()->assertJsonPath('data.usuario_id', $persona->id)->json('data.id');

        $this->patchJson($url.'/'.$id, ['proceso_seleccion' => 'CAS 002-2025'])->assertOk()->assertJsonPath('data.proceso_seleccion', 'CAS 002-2025');
        $this->patchJson($url.'/'.$id, ['fecha_cese' => '2025-01-01'])->assertUnprocessable()->assertJsonValidationErrors('fecha_cese');
        $this->patchJson($url.'/'.$id, ['fecha_cese' => '2025-06-30'])->assertOk();
        $this->patchJson($url.'/'.$id, ['nombre_puesto' => 'Otro'])->assertConflict();
        $this->patchJson($this->url(Usuario::factory()->create(), 'vinculos-laborales').'/'.$id, ['nombre_puesto' => 'Otro'])->assertConflict();
    }

    public function test_addenda_induction_and_trial_period_store_author_and_validate_owner(): void
    {
        $autor = $this->actuarComo();
        $persona = Usuario::factory()->create();
        Legajo::factory()->create(['usuario_id' => $persona->id]);
        $vinculo = VinculoLaboral::factory()->create(['usuario_id' => $persona->id, 'tiene_periodo_prueba' => true]);
        $ajeno = VinculoLaboral::factory()->create();
        $cesado = VinculoLaboral::factory()->create(['usuario_id' => $persona->id, 'fecha_inicio' => '2020-01-01', 'fecha_cese' => '2021-01-01']);
        $this->postJson($this->url($persona, 'vinculo-adendas'), ['vinculo_laboral_id' => $cesado->id, 'fecha_inicio' => '2020-06-01', 'fecha_fin' => '2020-12-31'])
            ->assertUnprocessable()->assertJsonValidationErrors('vinculo_laboral_id');

        $this->postJson($this->url($persona, 'vinculo-adendas'), ['vinculo_laboral_id' => $ajeno->id, 'fecha_inicio' => '2025-01-01', 'fecha_fin' => '2025-06-30'])
            ->assertUnprocessable()->assertJsonValidationErrors('vinculo_laboral_id');
        $this->postJson($this->url($persona, 'vinculo-adendas'), ['vinculo_laboral_id' => $vinculo->id])->assertUnprocessable()->assertJsonValidationErrors(['fecha_inicio', 'fecha_fin']);
        $this->postJson($this->url($persona, 'vinculo-adendas'), ['vinculo_laboral_id' => $vinculo->id, 'numero' => 'AD-1', 'fecha_inicio' => '2025-01-01', 'fecha_fin' => '2025-06-30', 'registrado_por' => $persona->id])
            ->assertUnprocessable()->assertJsonValidationErrors('registrado_por');
        $this->postJson($this->url($persona, 'vinculo-adendas'), ['vinculo_laboral_id' => $vinculo->id, 'numero' => 'AD-1', 'fecha_inicio' => '2025-01-01', 'fecha_fin' => '2025-06-30'])
            ->assertCreated()->assertJsonPath('data.registrado_por', $autor->id);

        $this->postJson($this->url($persona, 'inducciones'), ['vinculo_laboral_id' => $vinculo->id, 'tipo' => 'otra', 'fecha' => '2025-02-03'])->assertUnprocessable()->assertJsonValidationErrors('tipo');
        $this->postJson($this->url($persona, 'inducciones'), ['vinculo_laboral_id' => $vinculo->id, 'tipo' => 'general', 'fecha' => '2025-02-03', 'responsable' => 'ORH'])
            ->assertCreated()->assertJsonPath('data.registrado_por', $autor->id)->assertJsonPath('data.anulado', false);

        $periodo = $this->url($persona, 'periodos-prueba');
        $this->postJson($periodo, ['vinculo_laboral_id' => $vinculo->id, 'fecha_inicio' => '2025-02-01', 'fecha_fin' => '2025-01-01'])->assertUnprocessable()->assertJsonValidationErrors('fecha_fin');
        $this->postJson($periodo, ['vinculo_laboral_id' => $vinculo->id, 'fecha_inicio' => '2025-02-01', 'fecha_fin' => '2025-05-01', 'resultado' => 'aprobado'])->assertUnprocessable()->assertJsonValidationErrors('fecha_resultado');
        $id = $this->postJson($periodo, ['vinculo_laboral_id' => $vinculo->id, 'fecha_inicio' => '2025-02-01', 'fecha_fin' => '2025-05-01'])
            ->assertCreated()->assertJsonPath('data.resultado', 'pendiente')->assertJsonPath('data.registrado_por', $autor->id)->json('data.id');
        $this->patchJson($periodo.'/'.$id, ['resultado' => 'aprobado'])->assertUnprocessable()->assertJsonValidationErrors('fecha_resultado');
        // Corregir fechas = anular y registrar de nuevo: el PATCH solo acepta el resultado.
        $this->patchJson($periodo.'/'.$id, ['fecha_fin' => '2025-05-10'])->assertUnprocessable()->assertJsonValidationErrors('fecha_fin');
        $this->patchJson($periodo.'/'.$id, ['resultado' => 'aprobado', 'fecha_resultado' => '2025-01-15'])->assertUnprocessable()->assertJsonValidationErrors('fecha_resultado');
        $this->patchJson($periodo.'/'.$id, ['resultado' => 'aprobado', 'fecha_resultado' => '2025-05-02'])->assertOk()->assertJsonPath('data.resultado', 'aprobado');
        $this->patchJson($periodo.'/'.$id, ['fecha_fin' => '2025-05-10'])->assertConflict();

        $this->getJson($this->url($persona, 'seccion-02'))->assertOk()->assertJsonPath('data.tiene_periodo_prueba', true)
            ->assertJsonCount(1, 'data.vinculos.0.adendas')->assertJsonCount(0, 'data.vinculos.1.adendas')->assertJsonCount(1, 'data.inducciones')->assertJsonCount(1, 'data.periodos_prueba')
            ->assertJsonPath('data.inducciones.0.values.registrado_por', trim($autor->nombres.' '.$autor->apellido_paterno.' '.$autor->apellido_materno));
    }

    public function test_trial_period_is_rejected_when_the_person_has_none(): void
    {
        $this->actuarComo();
        $persona = Usuario::factory()->create(['tiene_periodo_prueba' => false]);
        Legajo::factory()->create(['usuario_id' => $persona->id]);
        $vinculo = VinculoLaboral::factory()->create(['usuario_id' => $persona->id, 'tiene_periodo_prueba' => false]);

        $this->postJson($this->url($persona, 'periodos-prueba'), ['vinculo_laboral_id' => $vinculo->id, 'fecha_inicio' => '2025-02-01', 'fecha_fin' => '2025-05-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('vinculo_laboral_id');
    }

    public function test_induction_and_trial_period_are_annulled_and_hidden_from_lists(): void
    {
        $this->actuarComo();
        $persona = Usuario::factory()->create();
        $induccion = Induccion::factory()->create(['usuario_id' => $persona->id, 'tipo' => 'general']);
        $periodo = PeriodoPrueba::factory()->create(['usuario_id' => $persona->id]);

        foreach (['inducciones' => $induccion, 'periodos-prueba' => $periodo] as $tipo => $registro) {
            $url = $this->url($persona, 'seccion-02/'.$tipo.'/'.$registro->id.'/anular');
            $this->postJson($url, ['motivo_anulacion' => 'no'])->assertUnprocessable();
            $this->postJson($this->url(Usuario::factory()->create(), 'seccion-02/'.$tipo.'/'.$registro->id.'/anular'), ['motivo_anulacion' => 'Ficha ajena'])->assertNotFound();
            $this->postJson($url, ['motivo_anulacion' => 'Registrado por error'])->assertOk()->assertJsonPath('data.anulado', true);
            $this->postJson($url, ['motivo_anulacion' => 'Segundo intento'])->assertConflict();
            // No hay ruta para borrar.
            $this->assertContains($this->deleteJson($this->url($persona, $tipo.'/'.$registro->id))->status(), [404, 405]);
            $this->assertDatabaseHas($registro->getTable(), ['id' => $registro->id, 'anulado' => true, 'motivo_anulacion' => 'Registrado por error']);
        }
        $this->getJson($this->url($persona, 'seccion-02'))->assertOk()->assertJsonCount(0, 'data.inducciones')->assertJsonCount(0, 'data.periodos_prueba');
    }

    public function test_permissions_are_validated_by_the_backend(): void
    {
        // asistente_legajo: ve y registra, pero no anula.
        $this->actuarComo(Usuario::factory()->create(), 'asistente_legajo');
        $persona = Usuario::factory()->create();
        Legajo::factory()->create(['usuario_id' => $persona->id]);
        $vinculo = VinculoLaboral::factory()->create(['usuario_id' => $persona->id]);
        $induccion = Induccion::factory()->create(['usuario_id' => $persona->id, 'vinculo_laboral_id' => $vinculo->id]);

        $this->getJson($this->url($persona, 'seccion-02'))->assertOk();
        $this->postJson($this->url($persona, 'inducciones'), ['vinculo_laboral_id' => $vinculo->id, 'tipo' => 'especifica', 'fecha' => '2025-02-03'])->assertCreated();
        $this->postJson($this->url($persona, 'seccion-02/inducciones/'.$induccion->id.'/anular'), ['motivo_anulacion' => 'Sin permiso'])->assertForbidden();

        config(['permisos.roles.lector_prueba' => ['legajo.ver']]);
        Sanctum::actingAs($this->asignarRol(Usuario::factory()->create(), 'lector_prueba')->refresh());
        $this->getJson($this->url($persona, 'seccion-02'))->assertOk();
        $this->postJson($this->url($persona, 'inducciones'), ['vinculo_laboral_id' => $vinculo->id, 'tipo' => 'general', 'fecha' => '2025-02-03'])->assertForbidden();
    }

    public function test_job_data_follows_the_personnel_type_of_the_link(): void
    {
        $this->actuarComo();
        $persona = Usuario::factory()->create();
        $docente = VinculoLaboral::factory()->create(['usuario_id' => $persona->id, 'tipo_personal' => 'docente', 'fecha_inicio' => '2024-01-01']);
        $administrativo = VinculoLaboral::factory()->create(['usuario_id' => $persona->id, 'tipo_personal' => 'administrativo', 'fecha_inicio' => '2023-01-01']);
        PerfilDocente::factory()->create(['vinculo_laboral_id' => $docente->id, 'condicion_docente' => 'ordinario']);
        PerfilAdministrativo::factory()->create(['vinculo_laboral_id' => $administrativo->id, 'cargo_estructural' => 'Jefe de unidad']);

        $this->getJson($this->url($persona, 'seccion-02'))->assertOk()
            ->assertJsonPath('data.vinculos.0.values.tipo_personal', 'docente')->assertJsonPath('data.vinculos.0.datos_puesto.0.values.condicion_docente', 'ordinario')
            ->assertJsonPath('data.vinculos.1.datos_puesto.0.values.cargo_estructural', 'Jefe de unidad')
            ->assertJsonMissingPath('data.vinculos.1.datos_puesto.0.values.condicion_docente');
    }
}
