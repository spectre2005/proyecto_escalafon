<?php

namespace Tests\Feature;

use App\Models\Desplazamiento;
use App\Models\EvaluacionDesempeno;
use App\Models\ProgresionCarrera;
use App\Models\RatificacionDocente;
use App\Models\UnidadOrganica;
use App\Models\Usuario;
use Database\Seeders\Seccion07DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Seccion07ApiTest extends TestCase
{
    use RefreshDatabase;

    private function persona(array $datos = []): Usuario
    {
        return Usuario::factory()->create($datos + ['nombres' => 'Ana', 'apellido_paterno' => 'Quispe', 'apellido_materno' => 'Mamani', 'numero_doc_identidad' => '40123456']);
    }

    public function test_lists_evaluations_without_annulled_with_person_and_hidden_score(): void
    {
        $this->actuarComo();
        $unidad = UnidadOrganica::factory()->create(['nombre' => 'Oficina de Personal']);
        $ana = $this->persona(['unidad_organica_id' => $unidad->id]);
        EvaluacionDesempeno::factory()->create(['usuario_id' => $ana->id, 'ciclo_anio' => 2025, 'calificacion' => 'Buen rendimiento', 'puntaje' => 85.5, 'plan_mejora' => true, 'fecha_evaluacion' => '2025-12-10']);
        EvaluacionDesempeno::factory()->create(['usuario_id' => $ana->id, 'ciclo_anio' => 2024, 'anulado' => true, 'motivo_anulacion' => 'Duplicado']);

        $response = $this->getJson('/api/v1/seccion07/evaluaciones')->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.ciclo_anio', 2025)
            ->assertJsonPath('data.0.fecha', '2025-12-10')
            ->assertJsonPath('data.0.plan_mejora', true)
            ->assertJsonPath('data.0.persona.id', $ana->id)
            ->assertJsonPath('data.0.persona.nombre', 'Quispe Mamani, Ana')
            ->assertJsonPath('data.0.persona.documento', '40123456')
            ->assertJsonPath('data.0.persona.unidad', 'Oficina de Personal')
            // Nadie tiene seccion07.ver_calificacion (pendiente del cliente), ni ADMIN.
            ->assertJsonPath('data.0.calificacion', null)
            ->assertJsonPath('data.0.puntaje', null)
            ->assertJsonPath('data.0.calificacion_oculta', true);
        $this->assertStringNotContainsString('Buen rendimiento', $response->getContent());
    }

    public function test_score_is_shown_only_with_its_own_permission(): void
    {
        config(['permisos.roles.evaluador_prueba' => ['seccion07.ver', 'seccion07.ver_calificacion'], 'permisos.pendientes' => []]);
        $this->actuarComo(Usuario::factory()->create(), 'evaluador_prueba');
        EvaluacionDesempeno::factory()->create(['ciclo_anio' => 2025, 'calificacion' => 'Buen rendimiento', 'puntaje' => 85.5]);

        $this->getJson('/api/v1/seccion07/evaluaciones')->assertOk()
            ->assertJsonPath('data.0.calificacion', 'Buen rendimiento')
            ->assertJsonPath('data.0.puntaje', '85.50')
            ->assertJsonPath('data.0.calificacion_oculta', false);
        $this->getJson('/api/v1/seccion07-opciones')->assertOk()->assertJsonPath('data.ve_calificacion', true);
    }

    public function test_filters_by_cycle_name_document_unit_and_improvement_plan(): void
    {
        $this->actuarComo();
        $unidad = UnidadOrganica::factory()->create();
        $ana = $this->persona(['unidad_organica_id' => $unidad->id]);
        $luis = Usuario::factory()->create(['nombres' => 'Luis', 'apellido_paterno' => 'Huaman', 'apellido_materno' => null, 'numero_doc_identidad' => '70999888']);
        EvaluacionDesempeno::factory()->create(['usuario_id' => $ana->id, 'ciclo_anio' => 2025, 'plan_mejora' => true]);
        EvaluacionDesempeno::factory()->create(['usuario_id' => $ana->id, 'ciclo_anio' => 2024, 'plan_mejora' => false]);
        EvaluacionDesempeno::factory()->create(['usuario_id' => $luis->id, 'ciclo_anio' => 2025, 'plan_mejora' => false]);
        $url = '/api/v1/seccion07/evaluaciones';

        $this->getJson($url.'?anio=2025')->assertOk()->assertJsonPath('total', 2);
        $this->getJson($url.'?q=quispe')->assertOk()->assertJsonPath('total', 2);
        $this->getJson($url.'?q=ana%20quispe')->assertOk()->assertJsonPath('total', 2);
        $this->getJson($url.'?q=huaman%20luis')->assertOk()->assertJsonPath('total', 1);
        $this->getJson($url.'?q=70999')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.persona.id', $luis->id);
        $this->getJson($url.'?unidad_organica_id='.$unidad->id)->assertOk()->assertJsonPath('total', 2);
        $this->getJson($url.'?plan_mejora=1')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.ciclo_anio', 2025);
        $this->getJson($url.'?anio=2025&plan_mejora=1&q=40123456')->assertOk()->assertJsonPath('total', 1);
        $this->getJson($url.'?anio=2023')->assertOk()->assertJsonPath('total', 0)->assertJsonCount(0, 'data');
    }

    public function test_pagination_is_real_and_ordered_by_most_recent_cycle(): void
    {
        $this->actuarComo();
        foreach (range(2001, 2025) as $anio) {
            EvaluacionDesempeno::factory()->create(['ciclo_anio' => $anio]);
        }
        $url = '/api/v1/seccion07/evaluaciones';

        $this->getJson($url)->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('last_page', 2)->assertJsonPath('data.0.ciclo_anio', 2025);
        $this->getJson($url.'?page=2')->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('data.4.ciclo_anio', 2001);
        $this->getJson($url.'?por_pagina=10&anio=2020')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($url.'?por_pagina=10')->assertOk()->assertJsonPath('last_page', 3)->assertJsonPath('per_page', 10);
        $this->getJson($url.'?por_pagina=100')->assertUnprocessable()->assertJsonValidationErrors('por_pagina');
        $this->getJson($url.'?page=2&por_pagina=10')->assertOk()->assertJsonPath('next_page_url', fn (string $url): bool => str_contains($url, 'por_pagina=10'));
    }

    public function test_other_section_07_types_filter_by_year_of_their_date(): void
    {
        $this->actuarComo();
        $origen = UnidadOrganica::factory()->create(['nombre' => 'Facultad A']);
        $destino = UnidadOrganica::factory()->create(['nombre' => 'Oficina B']);
        Desplazamiento::factory()->create(['tipo' => 'rotacion', 'fecha_inicio' => '2025-12-31', 'unidad_origen_id' => $origen->id, 'unidad_destino_id' => $destino->id]);
        Desplazamiento::factory()->create(['tipo' => 'destaque', 'fecha_inicio' => '2026-01-01']);
        ProgresionCarrera::factory()->create(['fecha_efectiva' => '2024-05-01', 'nivel_nuevo' => 'SP-ES']);
        RatificacionDocente::factory()->create(['fecha_resolucion' => '2023-03-15', 'fecha_vencimiento_periodo' => '2026-03-15']);
        RatificacionDocente::factory()->create(['fecha_resolucion' => '2023-04-01', 'anulado' => true]);

        $this->getJson('/api/v1/seccion07/desplazamientos?anio=2025')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.tipo', 'rotacion')->assertJsonPath('data.0.fecha', '2025-12-31')
            ->assertJsonPath('data.0.unidad_origen', 'Facultad A')->assertJsonPath('data.0.unidad_destino', 'Oficina B');
        $this->getJson('/api/v1/seccion07/desplazamientos')->assertOk()->assertJsonPath('total', 2)->assertJsonPath('data.0.fecha', '2026-01-01');
        $this->getJson('/api/v1/seccion07/progresiones?anio=2024')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.nivel_nuevo', 'SP-ES');
        $this->getJson('/api/v1/seccion07/ratificaciones')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.periodo_vencimiento', '2026-03-15');

        $this->getJson('/api/v1/seccion07-opciones')->assertOk()
            ->assertJsonPath('data.anios.desplazamientos', [2026, 2025])
            ->assertJsonPath('data.anios.ratificaciones', [2023])
            ->assertJsonPath('data.ve_calificacion', false)
            ->assertJsonCount(2, 'data.unidades');
    }

    public function test_requires_session_and_section_07_permission(): void
    {
        $this->getJson('/api/v1/seccion07/evaluaciones')->assertUnauthorized();

        $this->actuarComo(Usuario::factory()->create(), 'rol_sin_permisos');
        $this->getJson('/api/v1/seccion07/evaluaciones')->assertForbidden();
        $this->getJson('/api/v1/seccion07-opciones')->assertForbidden();

        $this->actuarComo(Usuario::factory()->create(), 'asistente_legajo');
        $this->getJson('/api/v1/seccion07/evaluaciones')->assertOk()->assertJsonPath('data', []);
        // El asistente consulta, pero el CRUD genérico (anular, crear) sigue siendo solo del administrador.
        $this->getJson('/api/v1/evaluaciones-desempeno')->assertForbidden();
    }

    public function test_demo_seeder_is_repeatable_and_hides_its_annulled_record(): void
    {
        $this->seed(Seccion07DemoSeeder::class);
        $this->seed(Seccion07DemoSeeder::class);
        $this->actuarComo();

        $this->getJson('/api/v1/seccion07/evaluaciones?por_pagina=50')->assertOk()->assertJsonPath('total', 20);
        $this->assertDatabaseCount('evaluaciones_desempeno', 21);
        $this->getJson('/api/v1/seccion07/progresiones')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/v1/seccion07/ratificaciones')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/v1/seccion07/desplazamientos')->assertOk()->assertJsonPath('total', 3);
    }

    public function test_rejects_unknown_types_and_invalid_filters_and_is_dev_only(): void
    {
        $this->actuarComo();
        $this->getJson('/api/v1/seccion07/sanciones')->assertNotFound();
        $this->getJson('/api/v1/seccion07/evaluaciones?anio=abc')->assertUnprocessable()->assertJsonValidationErrors('anio');
        $this->getJson('/api/v1/seccion07/evaluaciones?unidad_organica_id=x')->assertUnprocessable()->assertJsonValidationErrors('unidad_organica_id');
        $this->getJson('/api/v1/seccion07/evaluaciones?plan_mejora=quizas')->assertUnprocessable()->assertJsonValidationErrors('plan_mejora');

        $this->app->instance('env', 'production');
        $this->getJson('/api/v1/seccion07/evaluaciones')->assertForbidden();
    }
}
