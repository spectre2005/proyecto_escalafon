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
}
