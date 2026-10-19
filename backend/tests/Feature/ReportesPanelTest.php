<?php

namespace Tests\Feature;

use App\Models\Legajo;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\OperacionesAdministrativas;
use Tests\TestCase;

class ReportesPanelTest extends TestCase
{
    use OperacionesAdministrativas, RefreshDatabase;

    public function test_matrix_exports_all_filtered_rows_and_formats_do_not_expose_sensitive_fields(): void
    {
        $this->prepararOperaciones();
        foreach (range(1, 23) as $n) {
            Legajo::factory()->create(['numero_legajo' => sprintf('PRUEBA-%03d', $n), 'usuario_id' => Usuario::factory()->create(['nombres' => 'Persona de prueba'])->id, 'estado_legajo' => 'activo']);
        }
        $otro = Legajo::factory()->create(['numero_legajo' => 'OTRO-001', 'estado_legajo' => 'pasivo']);
        $this->getJson('/api/v1/reportes')->assertOk()->assertJsonCount(4, 'data');
        $this->getJson('/api/v1/reportes/matriz?q=PRUEBA&estado=activo')->assertOk()->assertJsonPath('total', 23)->assertJsonCount(20, 'data')->assertJsonMissingPath('data.0.telefono');
        $this->getJson('/api/v1/reportes/matriz?q=PRUEBA&estado=activo&exportar=1')->assertOk()->assertJsonCount(23, 'data');
        $this->getJson('/api/v1/reportes/caratula')->assertUnprocessable();
        $this->getJson('/api/v1/reportes/caratula?legajo_id='.$otro->id)->assertOk()->assertJsonPath('data.0.confidencial', 'CONFIDENCIAL')->assertJsonPath('data.0.numero_legajo', 'OTRO-001');
        $this->actuarComo(codigo: 'asistente_legajo');
        $this->getJson('/api/v1/reportes')->assertForbidden();
        $this->getJson('/api/v1/reportes/matriz?exportar=1')->assertForbidden();
    }

    public function test_incorporation_is_document_incorporation_and_honors_dates_and_visibility(): void
    {
        $this->prepararOperaciones();
        $legajo = $this->legajoOperativo();
        $doc = $this->documentoOperativo($legajo);
        $doc->update(['folio_inicio' => 1, 'folio_fin' => 2, 'fecha_incorporacion' => '2026-10-10', 'motivo_incorporacion' => 'Incorporación documentada']);
        $url = '/api/v1/reportes/incorporaciones?desde=2026-10-01&hasta=2026-10-31&seccion=3';
        $this->getJson($url)->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.motivo', 'Incorporación documentada')->assertJsonPath('data.0.folios', '1–2');
        $this->getJson('/api/v1/reportes/incorporaciones?desde=2026-11-01')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/v1/reportes/referencias')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_search_finds_separate_names_identity_numbers_and_active_or_passive_legajos(): void
    {
        $this->prepararOperaciones();
        $persona = Usuario::factory()->create(['nombres' => 'Ana Lucía', 'apellido_paterno' => 'Torres', 'apellido_materno' => 'Quispe', 'numero_doc_identidad' => '99000841']);
        $activo = Legajo::factory()->create(['usuario_id' => $persona->id, 'numero_legajo' => 'DEMO-ACTIVO-001', 'estado_legajo' => 'activo']);
        $pasivo = Legajo::factory()->create(['usuario_id' => $persona->id, 'numero_legajo' => 'DEMO-PASIVO-002', 'estado_legajo' => 'pasivo']);
        Legajo::factory()->create(['usuario_id' => Usuario::factory()->create(['nombres' => 'Ana', 'apellido_paterno' => 'Rojas'])->id]);
        foreach (['/api/v1/operaciones/legajos', '/api/v1/reportes/matriz'] as $ruta) {
            foreach (['Ana Torres', 'Torres Ana', '  ANA   TORRES ', '99000841'] as $texto) {
                $this->getJson($ruta.'?'.http_build_query(['q' => $texto]))->assertOk()->assertJsonPath('total', 2);
            }
            $this->getJson($ruta.'?q=DEMO-PASIVO')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.'.(str_contains($ruta, '/operaciones/') ? 'id' : 'numero_legajo'), str_contains($ruta, '/operaciones/') ? $pasivo->id : $pasivo->numero_legajo);
            $this->getJson($ruta.'?q=SinCoincidencias')->assertOk()->assertJsonPath('total', 0);
        }
        $this->getJson('/api/v1/reportes/matriz?q=Ana%20Torres&estado=activo&exportar=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.numero_legajo', $activo->numero_legajo);
        $this->getJson('/api/v1/reportes/caratula?legajo_id='.$pasivo->id)->assertOk()->assertJsonPath('data.0.numero_legajo', $pasivo->numero_legajo);
    }
}
