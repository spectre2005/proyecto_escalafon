<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
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

    public function test_distinct_assistant_and_admin_accounts_enforce_review_and_own_upload_rules(): void
    {
        $admin = $this->prepararOperaciones();
        $legajo = $this->legajoOperativo();
        $asistente = $this->actuarComo(codigo: 'asistente_legajo');
        $documento = $this->documentoOperativo($legajo);
        $documento->update(['created_by' => $asistente->id, 'fecha_recepcion' => null, 'recepcionado_por' => null]);
        $url = '/api/v1/revision-documental/'.$documento->id;
        $aceptacion = ['estado' => 'aceptado', 'estado_anterior' => 'cargado', 'recepcion_confirmada' => true];

        $this->assertNotSame($admin->id, $asistente->id);
        $this->getJson('/api/v1/revision-documental')->assertForbidden();
        $this->postJson($url, $aceptacion)->assertForbidden();
        $this->postJson($url, ['estado' => 'observado', 'estado_anterior' => 'cargado', 'descripcion' => 'No debe guardar', 'plazo_subsanacion' => $this->fechaOperacion(5)])->assertForbidden();
        $this->postJson('/api/v1/expedientes/'.$legajo->usuario_id.'/documentos/'.$documento->id.'/recepcionar')->assertOk();
        $this->assertDatabaseHas('documentos_usuario', ['id' => $documento->id, 'estado' => 'cargado', 'recepcionado_por' => $asistente->id]);

        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/revision-documental?vista=por_revisar')->assertOk()->assertJsonPath('data.0.carga_propia', false);
        $this->postJson($url, $aceptacion)->assertOk();
        $this->assertDatabaseHas('documentos_usuario', ['id' => $documento->id, 'estado' => 'aceptado', 'created_by' => $asistente->id]);

        $propio = $this->documentoOperativo($legajo);
        $propio->update(['created_by' => $admin->id]);
        $this->getJson('/api/v1/revision-documental?vista=por_revisar')->assertOk()->assertJsonPath('data.0.carga_propia', true);
        $this->postJson('/api/v1/revision-documental/'.$propio->id, $aceptacion)->assertForbidden();
        $this->postJson('/api/v1/revision-documental/'.$propio->id, ['estado' => 'observado', 'estado_anterior' => 'cargado', 'descripcion' => 'Tampoco se permite observar la carga propia', 'plazo_subsanacion' => $this->fechaOperacion(5)])->assertForbidden();
        $this->assertDatabaseHas('documentos_usuario', ['id' => $propio->id, 'estado' => 'cargado']);
    }

    public function test_review_views_distinguish_registered_receipted_complete_and_observed_documents(): void
    {
        $this->prepararOperaciones();
        $legajo = $this->legajoOperativo();
        $legajo->usuario->update(['nombres' => 'Ana Lucía', 'apellido_paterno' => 'Torres', 'apellido_materno' => 'Quispe']);
        $pendiente = $this->documentoOperativo($legajo);
        $sinRecepcion = $this->documentoOperativo($legajo);
        $sinRecepcion->update(['estado' => 'aceptado', 'fecha_recepcion' => null, 'recepcionado_por' => null]);
        $completo = $this->documentoOperativo($legajo);
        $completo->update(['estado' => 'verificado']);
        $observado = $this->documentoOperativo($legajo);
        $observado->update(['estado' => 'observado']);

        $this->getJson('/api/v1/revision-documental?vista=por_revisar&q=Torres%20Ana')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('resumen.documentos', 4)->assertJsonPath('resumen.por_revisar', 2);
        $this->getJson('/api/v1/revision-documental?vista=completos')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $completo->id)->assertJsonPath('data.0.completo', true);
        $this->getJson('/api/v1/revision-documental?vista=observados')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $observado->id)->assertJsonPath('data.0.completo', false);
        $this->getJson('/api/v1/revision-documental?vista=invalida')->assertUnprocessable();
    }
}
