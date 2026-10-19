<?php

namespace Tests\Feature;

use App\Models\ArchivoDocumento;
use App\Models\DocumentoUsuario;
use App\Models\Legajo;
use App\Models\LegajoSeccion;
use App\Models\TipoDocumento;
use App\Models\VinculoLaboral;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\OperacionesAdministrativas;
use Tests\Concerns\PdfValido;
use Tests\TestCase;

class ArchivoLegajoPanelTest extends TestCase
{
    use OperacionesAdministrativas, PdfValido, RefreshDatabase;

    public function test_reentry_references_preserve_original_pdf_and_folios_and_reject_duplicates(): void
    {
        $this->prepararOperaciones();
        $pasivo = $this->legajoOperativo();
        $pasivo->update(['estado_legajo' => 'pasivo', 'fecha_pasivo' => $this->fechaOperacion(-10)]);
        $activo = Legajo::factory()->create(['usuario_id' => $pasivo->usuario_id, 'estado_legajo' => 'activo']);
        $vinculo = VinculoLaboral::factory()->create(['usuario_id' => $pasivo->usuario_id, 'fecha_inicio' => $this->fechaOperacion(-100), 'fecha_cese' => $this->fechaOperacion(-10)]);
        $doc = $this->documentoOperativo($pasivo);
        $doc->update(['vinculo_laboral_id' => $vinculo->id, 'folio_inicio' => 5, 'folio_fin' => 6]);
        $payload = ['documento_origen_id' => $doc->id, 'vinculo_laboral_id' => $vinculo->id, 'motivo' => 'Referencia por reingreso del servidor'];
        $url = '/api/v1/expedientes/'.$pasivo->usuario_id.'/archivo';
        $this->postJson($url.'/referencias', $payload)->assertCreated()->assertJsonPath('data.legajo_id', $activo->id)->assertJsonPath('data.folio_inicio_pasivo', 5);
        $this->postJson($url.'/referencias', $payload)->assertConflict();
        $this->assertDatabaseCount('documentos_usuario', 1);
        $this->assertDatabaseCount('archivos_documento', 1);
        $this->assertSame(5, $doc->refresh()->folio_inicio);
        $this->getJson($url)->assertOk()->assertJsonPath('data.referencias.total', 1);
        $doc->tipoDocumento->update(['es_dato_sensible' => true]);
        $this->actuarComo(codigo: 'asistente_legajo');
        $this->getJson($url)->assertOk()->assertJsonPath('data.referencias.total', 0);
        $this->getJson('/api/v1/referencias-legajo-pasivo')->assertForbidden();
        $this->getJson('/api/v1/expedientes/'.$pasivo->usuario_id.'/referencias-legajo-pasivo')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_transfer_requires_age_inventory_and_microform_reference_and_preserves_documents(): void
    {
        $this->prepararOperaciones();
        $legajo = $this->legajoOperativo();
        $legajo->update(['estado_legajo' => 'pasivo', 'fecha_pasivo' => now('America/Lima')->subYears(4)->toDateString(), 'formato_legajo' => 'digital']);
        $datos = ['fecha_transferencia' => $this->fechaOperacion(), 'ubicacion' => 'Archivo central - estante 4', 'entidad_destino' => 'Archivo central institucional', 'numero_inventario' => 'INV-001', 'numero_documento_sustento' => 'OF-001', 'motivo' => 'Transferencia documentada al archivo central'];
        $url = '/api/v1/archivo/legajos/'.$legajo->id.'/transferir';
        $this->postJson($url, $datos)->assertUnprocessable();
        $datos['inventario_pdf'] = UploadedFile::fake()->createWithContent('inventario.pdf', self::pdfValido(2));
        $this->postJson($url, $datos)->assertUnprocessable();
        $datos['referencia_microforma'] = 'Constancia institucional MC-001';
        $id = $this->postJson($url, $datos)->assertCreated()->json('data.id');
        $this->assertSame('Archivo central - estante 4', $legajo->refresh()->ubicacion);
        $this->assertDatabaseCount('documentos_usuario', 0);
        $this->assertTrue(ArchivoDocumento::query()->sole()->cifrado);
        $this->assertSame(2, ArchivoDocumento::query()->sole()->num_paginas);
        $this->get('/api/v1/archivo/movimientos/'.$id.'/inventario')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->postJson($url, $datos)->assertConflict();
        $this->assertDatabaseCount('movimientos_custodia_legajo', 1);
        $this->actuarComo(codigo: 'asistente_legajo');
        $this->getJson('/api/v1/archivo/movimientos/'.$id.'/inventario')->assertForbidden();
        $this->postJson($url, $datos)->assertForbidden();
    }

    public function test_section_twelve_catalog_is_optional_and_filters_sensitive_types(): void
    {
        $this->prepararOperaciones();
        $legajo = $this->legajoOperativo();
        $url = '/api/v1/expedientes/'.$legajo->usuario_id.'/seccion-12';
        $this->getJson($url)->assertOk()->assertJsonCount(2, 'data.tipos')->assertJsonPath('data.tipos.0.exigido', false);
        $this->actuarComo(codigo: 'asistente_legajo');
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data.tipos');
    }

    public function test_section_twelve_accepts_real_page_count_and_preserves_folios_on_rejected_sensitive_upload(): void
    {
        $this->prepararOperaciones();
        $legajo = $this->legajoOperativo();
        $tipo = TipoDocumento::query()->where('codigo', 'S12_OTROS_DOCUMENTOS')->sole();
        $datos = ['seccion_legajo_id' => 12, 'tipo_documento_id' => $tipo->id, 'pdf' => UploadedFile::fake()->createWithContent('adicional.pdf', self::pdfValido(3)), 'folio_inicio' => 1, 'folio_fin' => 3,
            'fecha_incorporacion' => $this->fechaOperacion(), 'fecha_emision' => $this->fechaOperacion(), 'emisor' => 'ORH', 'titular_nombre' => 'Persona de prueba', 'titular_documento' => $legajo->usuario->numero_doc_identidad,
            'modalidad_presentacion' => 'copia_simple', 'relacion_titular' => 'servidor', 'motivo_incorporacion' => 'Documentación complementaria institucional'];
        $url = '/api/v1/expedientes/'.$legajo->usuario_id.'/documentos';
        $this->postJson($url, $datos)->assertCreated();
        $this->assertSame(3, DocumentoUsuario::query()->sole()->folio_fin);
        $this->getJson('/api/v1/expedientes/'.$legajo->usuario_id.'/seccion-12')->assertOk()->assertJsonPath('data.tipos.0.documentos', 1);
        $this->actuarComo(codigo: 'asistente_legajo');
        $sensible = TipoDocumento::query()->where('codigo', 'S12_OTROS_SENSIBLES')->sole();
        $this->postJson($url, [...$datos, 'tipo_documento_id' => $sensible->id, 'folio_inicio' => 4, 'folio_fin' => 6])->assertForbidden();
        $this->assertDatabaseCount('documentos_usuario', 1);
        $this->assertSame(3, LegajoSeccion::query()->where('legajo_id', $legajo->id)->where('seccion_legajo_id', 12)->value('ultimo_folio'));
    }
}
