<?php

namespace Tests\Feature;

use App\Models\ArchivoDocumento;
use App\Models\DocumentoUsuario;
use App\Models\Usuario;
use App\Support\Documentos\DocumentoProtegido;
use App\Support\Documentos\EstadoDocumento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Directiva, arts. 2.3 y 5.3: las reglas viven en el modelo, no en cada controlador. */
class DocumentoProtegidoTest extends TestCase
{
    use RefreshDatabase;

    private function documento(array $datos = []): DocumentoUsuario
    {
        return DocumentoUsuario::factory()->create([
            'archivo_id' => ArchivoDocumento::factory()->create()->id, 'estado' => 'aceptado', 'folio_inicio' => 1, 'folio_fin' => 2, ...$datos,
        ]);
    }

    public function test_documents_and_files_cannot_be_deleted(): void
    {
        $documento = $this->documento();
        foreach ([$documento, $documento->archivo] as $registro) {
            try {
                $registro->delete();
                $this->fail('Se eliminó '.$registro::class);
            } catch (DocumentoProtegido) {
                $this->assertModelExists($registro);
            }
        }
    }

    public function test_identity_fields_cannot_change_but_descriptive_data_and_state_can(): void
    {
        $documento = $this->documento();
        foreach (DocumentoUsuario::CAMPOS_INMUTABLES as $campo) {
            $valor = in_array($campo, ['folio_inicio', 'folio_fin', 'seccion_legajo_id'], true) ? 99 : $this->documento()->getAttribute($campo === 'subsana_a_documento_id' ? 'id' : $campo);
            try {
                $documento->fresh()->update([$campo => $valor]);
                $this->fail("Se modificó {$campo}");
            } catch (DocumentoProtegido $e) {
                $this->assertStringContainsString($campo, $e->getMessage());
            }
        }
        $documento->fresh()->update(['emisor' => 'Otro emisor', 'estado' => 'observado']);
        $this->assertSame(['Otro emisor', 'observado'], [$documento->fresh()->emisor, $documento->fresh()->estado]);
    }

    public function test_annulled_document_never_returns_to_another_state(): void
    {
        $documento = $this->documento();
        $documento->update(['estado' => 'anulado']);
        $this->expectException(DocumentoProtegido::class);
        $documento->fresh()->update(['estado' => 'aceptado']);
    }

    public function test_file_only_changes_its_antivirus_result(): void
    {
        $archivo = $this->documento()->archivo;
        $archivo->update(['estado_antivirus' => 'limpio']);
        $this->assertSame('limpio', $archivo->fresh()->estado_antivirus);
        $this->expectException(DocumentoProtegido::class);
        $archivo->fresh()->update(['storage_path' => 'otra/ruta.pdf']);
    }

    public function test_scopes_exclude_annulled_and_superseded_versions(): void
    {
        $vigente = $this->documento();
        $anulado = $this->documento(['estado' => 'anulado']);
        $subsanado = $this->documento();
        $nuevo = $this->documento(['subsana_a_documento_id' => $subsanado->id]);

        $ids = DocumentoUsuario::query()->noAnulados()->sinSubsanar()->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$vigente->id, $nuevo->id], $ids);
        $this->assertNotContains($anulado->id, $ids);
    }

    public function test_generic_api_offers_no_way_to_delete_documents(): void
    {
        $this->actuarComo();
        $documento = $this->documento();
        // La ruta no existe (405); si alguna ruta futura lo intentara, el modelo lo impide con DocumentoProtegido (409).
        $this->deleteJson('/api/v1/documentos-usuario/'.$documento->id)->assertStatus(405);
        $this->assertModelExists($documento);
    }

    public function test_observed_false_and_annulled_documents_never_count_as_approved_even_if_received(): void
    {
        foreach (['observado', 'falso', 'anulado', 'pendiente'] as $estado) {
            $documento = $this->documento(['estado' => $estado, 'fecha_recepcion' => today(), 'recepcionado_por' => Usuario::factory()->create()->id]);
            $this->assertFalse(EstadoDocumento::aprobado($documento, $documento->tipoDocumento), $estado);
        }
        $aceptado = $this->documento();
        $this->assertTrue(EstadoDocumento::aprobado($aceptado, $aceptado->tipoDocumento));
        $recepcionado = $this->documento(['estado' => 'cargado', 'fecha_recepcion' => today(), 'recepcionado_por' => Usuario::factory()->create()->id]);
        $recepcionado->tipoDocumento->update(['acredita_perfil_puesto' => false]);
        $this->assertTrue(EstadoDocumento::aprobado($recepcionado->fresh(), $recepcionado->tipoDocumento->fresh()));
    }
}
