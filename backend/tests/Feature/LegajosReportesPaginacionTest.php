<?php

namespace Tests\Feature;

use App\Models\DocumentoUsuario;
use App\Models\Legajo;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Paginación común (PorPagina) en el directorio de Legajos, los registros de cada sección y Reportes. */
class LegajosReportesPaginacionTest extends TestCase
{
    use RefreshDatabase;

    public function test_directory_uses_ten_by_default_keeps_filters_and_reports_out_of_range_pages(): void
    {
        $this->actuarComo();
        Usuario::factory()->count(24)->create(['tipo_personal' => 'docente', 'apellido_paterno' => 'Paginado']);
        $url = '/api/v1/expedientes?q=paginado&tipo=docente';
        $ids = [];
        foreach ([1 => 10, 2 => 10, 3 => 4] as $pagina => $filas) {
            $ids = [...$ids, ...$this->getJson($url.'&page='.$pagina)->assertOk()->assertJsonCount($filas, 'data')
                ->assertJsonPath('total', 24)->assertJsonPath('per_page', 10)->assertJsonPath('last_page', 3)->json('data.*.id')];
        }
        $this->assertCount(24, array_unique($ids));
        $this->getJson($url.'&por_pagina=15')->assertOk()->assertJsonCount(15, 'data')->assertJsonPath('last_page', 2)
            ->assertJsonPath('next_page_url', fn (string $u): bool => str_contains($u, 'q=paginado') && str_contains($u, 'tipo=docente') && str_contains($u, 'por_pagina=15') && str_contains($u, 'page=2'));
        $this->getJson($url.'&por_pagina=20')->assertOk()->assertJsonCount(20, 'data');
        $this->getJson($url.'&page=9')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('current_page', 9)->assertJsonPath('last_page', 3);
        $this->getJson('/api/v1/expedientes?q=NOEXISTE&page=2')->assertOk()->assertJsonPath('total', 0);
        foreach (['por_pagina=50', 'por_pagina=100', 'por_pagina=abc'] as $malo) {
            $this->getJson('/api/v1/expedientes?'.$malo)->assertUnprocessable()->assertJsonValidationErrors('por_pagina');
        }
    }

    public function test_section_records_paginate_and_keep_hiding_sensitive_documents_on_later_pages(): void
    {
        $this->actuarComo();
        $persona = Usuario::factory()->create();
        $legajo = Legajo::factory()->create(['usuario_id' => $persona->id, 'estado_legajo' => 'activo']);
        $comun = TipoDocumento::factory()->create(['seccion_legajo_id' => 3]);
        $sensible = TipoDocumento::factory()->create(['seccion_legajo_id' => 1, 'es_dato_sensible' => true]);
        DocumentoUsuario::factory()->count(12)->create(['usuario_id' => $persona->id, 'legajo_id' => $legajo->id, 'tipo_documento_id' => $comun->id, 'seccion_legajo_id' => 3]);
        DocumentoUsuario::factory()->count(9)->create(['usuario_id' => $persona->id, 'legajo_id' => $legajo->id, 'tipo_documento_id' => $sensible->id, 'seccion_legajo_id' => 1]);
        $url = '/api/v1/expedientes/'.$persona->id.'/documentos-usuario';
        $this->getJson($url)->assertOk()->assertJsonCount(10, 'data')->assertJsonPath('total', 21)->assertJsonPath('per_page', 10);
        $this->getJson($url.'?por_pagina=20&page=2')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($url.'?page=5')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('current_page', 5)->assertJsonPath('last_page', 3);
        $this->getJson($url.'?por_pagina=50')->assertUnprocessable()->assertJsonValidationErrors('por_pagina');
        // Sin datos sensibles: los 9 documentos de la sección 01 no aparecen en ninguna página.
        $this->actuarComo(codigo: 'asistente_legajo');
        $ids = [];
        foreach ([1, 2] as $pagina) {
            $ids = [...$ids, ...$this->getJson($url.'?page='.$pagina)->assertOk()->assertJsonPath('total', 12)->assertJsonPath('last_page', 2)->json('data.*.id')];
        }
        $this->assertCount(12, $ids);
        $this->assertSame(0, DocumentoUsuario::query()->whereIn('id', $ids)->where('tipo_documento_id', $sensible->id)->count());
    }

    public function test_reports_use_ten_by_default_keep_api_compatible_sizes_and_report_out_of_range_pages(): void
    {
        $this->actuarComo();
        Usuario::factory()->has(Legajo::factory(), 'legajos')->count(23)->create(['apellido_paterno' => 'ReportePaginado']);
        $url = '/api/v1/reportes/personal?q=ReportePaginado';
        $this->getJson($url)->assertOk()->assertJsonCount(10, 'data')->assertJsonPath('meta.per_page', 10)->assertJsonPath('meta.last_page', 3);
        $this->getJson($url.'&por_pagina=15')->assertOk()->assertJsonCount(15, 'data')->assertJsonPath('meta.last_page', 2);
        $this->getJson($url.'&por_pagina=20')->assertOk()->assertJsonCount(20, 'data');
        // 50 y 100 se siguen aceptando por compatibilidad con la API (la pantalla ofrece 10, 15 y 20).
        $this->getJson($url.'&por_pagina=50')->assertOk()->assertJsonCount(23, 'data')->assertJsonPath('meta.per_page', 50);
        $this->getJson($url.'&por_pagina=100')->assertOk()->assertJsonPath('meta.per_page', 100);
        $this->getJson($url.'&por_pagina=5')->assertUnprocessable()->assertJsonValidationErrors('por_pagina');
        $this->getJson($url.'&page=8')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.current_page', 8)->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('meta.filtros', fn (array $filtros): bool => ! array_key_exists('por_pagina', $filtros) && ! array_key_exists('page', $filtros));
    }
}
