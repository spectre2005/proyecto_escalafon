<?php

namespace Tests\Feature;

use App\Models\EvaluacionDesempeno;
use App\Models\Usuario;
use Database\Seeders\EvaluacionDesempenoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EvaluacionDesempenoPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actuarComo();
    }

    public function test_index_includes_fictional_person_evaluator_and_cycle_options_without_internal_fields(): void
    {
        $person = Usuario::factory()->create([
            'nombres' => 'Persona Ficticia',
            'apellido_paterno' => 'Evaluada',
        ]);
        $evaluator = Usuario::factory()->create([
            'nombres' => 'Evaluador Ficticio',
            'apellido_paterno' => 'Prueba',
        ]);
        $registeredBy = Usuario::factory()->create();
        $evaluation = EvaluacionDesempeno::factory()->create([
            'usuario_id' => $person->id,
            'evaluador_id' => $evaluator->id,
            'registrado_por' => $registeredBy->id,
            'ciclo_anio' => 2025,
            'fecha_evaluacion' => '2025-08-15',
            'calificacion' => 'Satisfactorio ficticio',
            'puntaje' => 85.5,
            'plan_mejora' => true,
            'anulado' => false,
            'motivo_anulacion' => 'Texto interno ficticio',
        ]);

        config(['permisos.roles.ADMIN' => ['*', 'configuracion.modulos']]);
        $this->getJson('/api/v1/evaluaciones-desempeno')
            ->assertOk()
            ->assertJsonPath('data.0.id', $evaluation->id)
            ->assertJsonPath('data.0.usuario.nombres', 'Persona Ficticia')
            ->assertJsonPath('data.0.evaluador.nombres', 'Evaluador Ficticio')
            ->assertJsonPath('data.0.ciclo_anio', 2025)
            // Una cuenta sin permiso explícito no recibe calificación ni puntaje.
            ->assertJsonPath('data.0.puntaje', null)
            ->assertJsonPath('data.0.calificacion', null)
            ->assertJsonPath('data.0.plan_mejora', true)
            ->assertJsonPath('filters.ciclos.0', 2025)
            ->assertJsonMissingPath('data.0.registrado_por')
            ->assertJsonMissingPath('data.0.motivo_anulacion')
            ->assertJsonStructure(['data', 'links', 'meta', 'filters']);
    }

    public function test_index_filters_by_person_name_and_cycle(): void
    {
        $person = Usuario::factory()->create([
            'nombres' => 'Persona Buscada',
            'apellido_paterno' => 'Ficticia',
        ]);
        $otherPerson = Usuario::factory()->create();

        $matching = EvaluacionDesempeno::factory()->create([
            'usuario_id' => $person->id,
            'ciclo_anio' => 2025,
            'calificacion' => 'Calificación ficticia',
        ]);
        EvaluacionDesempeno::factory()->create([
            'usuario_id' => $otherPerson->id,
            'ciclo_anio' => 2024,
        ]);

        $this->getJson('/api/v1/evaluaciones-desempeno?q=buscada&ciclo_anio=2025')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $matching->id);
    }

    public function test_index_rejects_invalid_search_and_cycle_filters(): void
    {
        $this->getJson('/api/v1/evaluaciones-desempeno?page=0')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('page');

        $this->getJson('/api/v1/evaluaciones-desempeno?q='.str_repeat('a', 121))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('q');

        $this->getJson('/api/v1/evaluaciones-desempeno?ciclo_anio=actual')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ciclo_anio');
    }

    public function test_fictional_seeder_creates_ten_repeatable_evaluations(): void
    {
        $this->seed(EvaluacionDesempenoSeeder::class);

        $this->assertDatabaseCount('evaluaciones_desempeno', 10);

        $this->seed(EvaluacionDesempenoSeeder::class);

        $this->assertDatabaseCount('evaluaciones_desempeno', 10);
        $this->getJson('/api/v1/evaluaciones-desempeno')
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonCount(5, 'filters.ciclos');
    }
}
