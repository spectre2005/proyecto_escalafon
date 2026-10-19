<?php

namespace Tests\Feature;

use App\Models\AuditoriaEvento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\OperacionesAdministrativas;
use Tests\TestCase;

class FedateoDocumentoTest extends TestCase
{
    use OperacionesAdministrativas;
    use RefreshDatabase;

    public function test_fedateo_requires_same_legajo_pdf_and_preserves_evidence_and_history(): void
    {
        $admin = $this->prepararOperaciones();
        $legajo = $this->legajoOperativo();
        $doc = $this->documentoOperativo($legajo);
        $sustento = $this->documentoOperativo($legajo);
        $ajeno = $this->documentoOperativo($this->legajoOperativo());
        $url = '/api/v1/revision-documental/'.$doc->id.'/fedatear';
        $datos = ['sustento_documento_id' => $sustento->id, 'sustento_anterior_id' => null, 'estado_anterior' => 'cargado',
            'fecha_fedateo' => $this->fechaOperacion(), 'motivo' => 'Contraste con original físico y registro de sustento'];
        $this->postJson($url, [...$datos, 'sustento_documento_id' => null])->assertUnprocessable();
        $this->postJson($url, [...$datos, 'sustento_documento_id' => $ajeno->id])->assertNotFound();
        $this->postJson($url, [...$datos, 'sustento_documento_id' => $doc->id])->assertUnprocessable();
        $this->postJson($url, $datos)->assertOk()->assertJsonPath('data.fedateado', true)->assertJsonPath('data.fedatario_id', $admin->id)
            ->assertJsonPath('data.fedateo_sustento_documento_id', $sustento->id);
        $this->postJson($url, $datos)->assertConflict();
        $otro = $this->documentoOperativo($legajo);
        $this->postJson($url, [...$datos, 'sustento_anterior_id' => $sustento->id, 'sustento_documento_id' => $otro->id, 'motivo' => 'Sustento adicional de la corrección'])->assertOk();
        $this->assertSame(2, AuditoriaEvento::query()->where('entidad_id', $doc->id)->where('accion', 'documento.fedateado_con_sustento')->count());
        $this->assertDatabaseHas('documentos_usuario', ['id' => $sustento->id, 'archivo_id' => $sustento->archivo_id]);
        $this->assertDatabaseHas('documentos_usuario', ['id' => $doc->id, 'archivo_id' => $doc->archivo_id]);
        $this->actuarComo(codigo: 'asistente_legajo');
        $this->postJson($url, $datos)->assertForbidden();
    }

    public function test_acceptance_cannot_register_fedateo_and_legacy_flag_without_evidence_is_insufficient(): void
    {
        $admin = $this->prepararOperaciones();
        $legajo = $this->legajoOperativo();
        $doc = $this->documentoOperativo($legajo);
        $doc->update(['created_by' => $admin->id, 'fedateado' => false, 'fecha_fedateo' => null, 'fedatario_id' => null, 'fedateo_sustento_documento_id' => null]);
        $doc->tipoDocumento->update(['requiere_fedateo' => true]);
        $url = '/api/v1/revision-documental/'.$doc->id;
        $aceptar = ['estado' => 'aceptado', 'estado_anterior' => 'cargado', 'recepcion_confirmada' => true];
        $this->postJson($url, [...$aceptar, 'fedateado' => true])->assertUnprocessable()->assertJsonValidationErrors('fedateado');
        $this->assertDatabaseHas('documentos_usuario', ['id' => $doc->id, 'estado' => 'cargado', 'fedateado' => false, 'fedatario_id' => null]);
        $doc->update(['fedateado' => true, 'fecha_fedateo' => $this->fechaOperacion(), 'fedatario_id' => $admin->id]);
        $this->postJson($url, $aceptar)->assertUnprocessable()->assertJsonValidationErrors('fedateado');
        $sustento = $this->documentoOperativo($legajo);
        $this->postJson($url.'/fedatear', ['sustento_documento_id' => $sustento->id, 'sustento_anterior_id' => null,
            'estado_anterior' => 'cargado', 'fecha_fedateo' => $this->fechaOperacion(), 'motivo' => 'Registro completo por el administrador que cargó el PDF'])->assertOk();
        $fecha = $doc->refresh()->fecha_fedateo;
        $this->postJson($url, $aceptar)->assertOk();
        $this->assertEquals($fecha, $doc->refresh()->fecha_fedateo);
        $this->assertSame($sustento->id, $doc->fedateo_sustento_documento_id);
        $this->assertSame(1, AuditoriaEvento::query()->where('entidad_id', $doc->id)->where('accion', 'documento.fedateado_con_sustento')->count());
    }

    public function test_optional_fedateo_cannot_be_claimed_without_evidence_and_unavailable_evidence_blocks_acceptance(): void
    {
        $this->prepararOperaciones();
        $legajo = $this->legajoOperativo();
        $doc = $this->documentoOperativo($legajo);
        $doc->update(['fedateado' => false]);
        $url = '/api/v1/revision-documental/'.$doc->id;
        $aceptar = ['estado' => 'aceptado', 'estado_anterior' => 'cargado', 'recepcion_confirmada' => true];
        $this->postJson($url, [...$aceptar, 'fedateado' => true])->assertUnprocessable();
        $this->postJson($url, $aceptar)->assertOk()->assertJsonPath('data.fedateado', false);
        $otro = $this->documentoOperativo($legajo);
        $sustento = $this->documentoOperativo($legajo);
        $otro->tipoDocumento->update(['requiere_fedateo' => true]);
        $this->postJson('/api/v1/revision-documental/'.$otro->id.'/fedatear', ['sustento_documento_id' => $sustento->id,
            'sustento_anterior_id' => null, 'estado_anterior' => 'cargado', 'fecha_fedateo' => $this->fechaOperacion(), 'motivo' => 'Registro de sustento para comprobar su disponibilidad'])->assertOk();
        $sustento->update(['estado' => 'anulado']);
        $this->postJson('/api/v1/revision-documental/'.$otro->id, $aceptar)->assertUnprocessable()->assertJsonValidationErrors('fedateado');
        $this->assertDatabaseHas('documentos_usuario', ['id' => $otro->id, 'estado' => 'cargado']);
    }
}
