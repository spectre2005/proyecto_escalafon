<?php

namespace Tests\Feature;

use App\Models\Legajo;
use App\Models\RequerimientoDocumento;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use Database\Seeders\SeccionesLegajoCatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Paginación común (PorPagina) en la lista de requerimientos y en Sugerencias, sin cambiar sus consultas. */
class RequerimientosPaginacionTest extends TestCase
{
    use RefreshDatabase;

    private function requerimientos(int $cantidad, array $datos = []): void
    {
        $u = Usuario::factory()->create();
        Legajo::factory()->create(['usuario_id' => $u->id, 'estado_legajo' => 'activo']);
        RequerimientoDocumento::factory()->count($cantidad)->create(['usuario_id' => $u->id, 'clase' => 'documentos', 'estado' => 'abierto', ...$datos]);
    }

    public function test_main_list_uses_ten_by_default_and_the_allowed_sizes(): void
    {
        $this->actuarComo();
        $this->requerimientos(23);
        $primera = $this->getJson('/api/v1/solicitudes-legajo?clase=documentos')->assertOk()->assertJsonCount(10, 'data')
            ->assertJsonPath('total', 23)->assertJsonPath('per_page', 10)->assertJsonPath('last_page', 3)->assertJsonPath('from', 1)->assertJsonPath('to', 10);
        $segunda = $this->getJson('/api/v1/solicitudes-legajo?clase=documentos&page=2')->assertOk()->assertJsonCount(10, 'data')->assertJsonPath('from', 11);
        $tercera = $this->getJson('/api/v1/solicitudes-legajo?clase=documentos&page=3')->assertOk()->assertJsonCount(3, 'data');
        $this->assertCount(23, array_unique([...$primera->json('data.*.id'), ...$segunda->json('data.*.id'), ...$tercera->json('data.*.id')]));
        $this->getJson('/api/v1/solicitudes-legajo?clase=documentos&por_pagina=15')->assertOk()->assertJsonCount(15, 'data')->assertJsonPath('last_page', 2);
        $this->getJson('/api/v1/solicitudes-legajo?clase=documentos&por_pagina=20')->assertOk()->assertJsonCount(20, 'data');
        foreach (['por_pagina=50', 'por_pagina=100', 'por_pagina=abc', 'page=0'] as $malo) {
            $this->getJson('/api/v1/solicitudes-legajo?'.$malo)->assertUnprocessable();
        }
        // Los datos de cada fila y las claves propias de la respuesta no cambian.
        $primera->assertJsonStructure(['data' => [['id', 'estado', 'seguimiento', 'correo', 'atrasado']], 'correo_configurado', 'recepcion_automatica', 'avisos']);
    }

    public function test_filters_and_search_stay_in_the_page_links_and_out_of_range_pages_report_the_last_one(): void
    {
        $this->actuarComo();
        $this->requerimientos(12, ['estado' => 'observado', 'descripcion' => 'Constancia observada']);
        $this->requerimientos(5, ['estado' => 'abierto']);
        $this->getJson('/api/v1/solicitudes-legajo?clase=documentos&estado=observado&q=constancia&por_pagina=10')->assertOk()
            ->assertJsonPath('total', 12)->assertJsonPath('next_page_url', fn (string $url): bool => str_contains($url, 'estado=observado')
                && str_contains($url, 'q=constancia') && str_contains($url, 'por_pagina=10') && str_contains($url, 'clase=documentos') && str_contains($url, 'page=2'));
        // Página inexistente: sin filas, pero con la última válida para que el frontend corrija la URL.
        $this->getJson('/api/v1/solicitudes-legajo?clase=documentos&estado=observado&page=7')->assertOk()->assertJsonCount(0, 'data')
            ->assertJsonPath('total', 12)->assertJsonPath('current_page', 7)->assertJsonPath('last_page', 2);
        $this->getJson('/api/v1/solicitudes-legajo?clase=documentos&q=NOEXISTE&page=3')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_later_pages_keep_hiding_sensitive_requirements(): void
    {
        $sensible = TipoDocumento::factory()->create(['seccion_legajo_id' => 1, 'es_dato_sensible' => true]);
        $this->requerimientos(15, ['tipo_documento_id' => $sensible->id]);
        $this->requerimientos(11);
        $this->actuarComo();
        $this->getJson('/api/v1/solicitudes-legajo?clase=documentos')->assertOk()->assertJsonPath('total', 26);
        // El asistente gestiona requerimientos, pero no ve datos sensibles: ni en la primera página ni en las siguientes.
        $this->actuarComo(codigo: 'asistente_legajo');
        $ids = [];
        foreach ([1, 2] as $pagina) {
            $ids = [...$ids, ...$this->getJson('/api/v1/solicitudes-legajo?clase=documentos&page='.$pagina)->assertOk()
                ->assertJsonPath('total', 11)->assertJsonPath('last_page', 2)->json('data.*.id')];
        }
        $this->assertCount(11, $ids);
        $this->assertSame(0, RequerimientoDocumento::query()->whereIn('id', $ids)->where('tipo_documento_id', $sensible->id)->count());
    }

    public function test_suggestions_paginate_on_their_own_with_the_same_sizes(): void
    {
        $this->seed(SeccionesLegajoCatalogoSeeder::class);
        TipoDocumento::query()->update(['activo' => false]);
        TipoDocumento::factory()->create(['seccion_legajo_id' => 3, 'obligatorio' => true, 'activo' => true, 'requiere_colegiatura' => true,
            'aplica_carrera' => true, 'aplica_plazo_determinado' => true, 'aplica_designado' => true]);
        foreach (range(1, 12) as $i) {
            Legajo::factory()->create(['estado_legajo' => 'activo', 'usuario_id' => Usuario::factory()->create(['exige_colegiatura' => null])->id]);
        }
        $this->actuarComo();
        $url = '/api/v1/solicitudes-legajo/sugerencias';
        $this->getJson($url)->assertOk()->assertJsonCount(10, 'data')->assertJsonPath('meta.total', 12)->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.last_page', 2)->assertJsonPath('data.0.estado_codigo', 'por_determinar');
        $this->getJson($url.'?page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.current_page', 2);
        $this->getJson($url.'?por_pagina=15')->assertOk()->assertJsonCount(12, 'data')->assertJsonPath('meta.last_page', 1);
        $this->getJson($url.'?page=5')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.current_page', 5)->assertJsonPath('meta.last_page', 2);
        $this->getJson($url.'?por_pagina=50')->assertUnprocessable()->assertJsonValidationErrors('por_pagina');
        // La paginación de Sugerencias no mueve la lista principal ni al revés.
        $this->getJson('/api/v1/solicitudes-legajo?clase=documentos')->assertOk()->assertJsonPath('total', 0);
        config(['permisos.roles.prueba_sin_requerimientos' => ['legajo.ver']]);
        $this->actuarComo(codigo: 'prueba_sin_requerimientos');
        $this->getJson($url)->assertForbidden();
    }
}
