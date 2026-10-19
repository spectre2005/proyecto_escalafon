<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\OperacionesAdministrativas;
use Tests\TestCase;

class BandejaDocumentalTest extends TestCase
{
    use OperacionesAdministrativas;
    use RefreshDatabase;

    public function test_completion_requires_receipt_and_acceptance_and_review_preserves_original_evidence(): void
    {
        $admin = $this->prepararOperaciones();
        $legajo = $this->legajoOperativo();
        $doc = $this->documentoOperativo($legajo);
        $doc->update(['seccion_legajo_id' => 2]);
        $this->getJson('/api/v1/revision-documental')->assertOk()->assertJsonPath('resumen.documentos', 1)->assertJsonPath('resumen.completos', 0);
        $this->postJson('/api/v1/revision-documental/'.$doc->id, ['estado' => 'aceptado', 'estado_anterior' => 'cargado', 'recepcion_confirmada' => true])->assertOk();
        $this->getJson('/api/v1/revision-documental')->assertOk()->assertJsonPath('resumen.completos', 1);
        $this->postJson('/api/v1/revision-documental/'.$doc->id, ['estado' => 'observado', 'estado_anterior' => 'aceptado', 'descripcion' => 'Debe subsanar con nueva evidencia', 'plazo_subsanacion' => $this->fechaOperacion(5)])->assertOk();
        $this->getJson('/api/v1/revision-documental')->assertOk()->assertJsonPath('resumen.completos', 0)->assertJsonPath('resumen.observados', 1);
        $this->assertSame($doc->archivo_id, $doc->refresh()->archivo_id);
        $this->assertDatabaseCount('observaciones_documento', 1);
        $propio = $this->documentoOperativo($legajo);
        $propio->update(['created_by' => $admin->id]);
        $this->postJson('/api/v1/revision-documental/'.$propio->id, ['estado' => 'aceptado', 'estado_anterior' => 'cargado', 'recepcion_confirmada' => true])->assertForbidden();
        $this->actuarComo(codigo: 'asistente_legajo');
        $this->getJson('/api/v1/revision-documental')->assertForbidden();
    }

    public function test_listing_hides_sensitive_documents_and_subsanados_and_closed_legajos(): void
    {
        $this->prepararOperaciones();
        $legajo = $this->legajoOperativo();
        $viejo = $this->documentoOperativo($legajo);
        $nuevo = $this->documentoOperativo($legajo);
        $nuevo->update(['subsana_a_documento_id' => $viejo->id]);
        $this->getJson('/api/v1/revision-documental')->assertOk()->assertJsonCount(1, 'data');
        config(['permisos.roles.LECTOR' => ['legajo.ver', 'documento.revisar']]);
        $this->actuarComo(codigo: 'LECTOR');
        $nuevo->tipoDocumento->update(['es_dato_sensible' => true]);
        $this->getJson('/api/v1/revision-documental')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/revision-documental/'.$nuevo->id, ['estado' => 'aceptado', 'estado_anterior' => 'cargado', 'recepcion_confirmada' => true])->assertNotFound();
        $legajo->update(['estado_legajo' => 'pasivo']);
        $this->getJson('/api/v1/revision-documental')->assertOk()->assertJsonCount(0, 'data');
    }
}
