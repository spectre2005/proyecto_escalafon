<?php

namespace Tests\Feature;

use App\Models\DocumentoUsuario;
use App\Models\Legajo;
use App\Models\RegimenNormativo;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use Database\Seeders\Seccion02CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SubeDocumentos;
use Tests\TestCase;

class IncorporacionDocumentosTest extends TestCase
{
    use RefreshDatabase;
    use SubeDocumentos;

    private Usuario $persona;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 21));
        $this->actuarComo();
        $this->seed(Seccion02CatalogoSeeder::class);
        Storage::fake((string) config('legajo.disco_documentos'));
        $this->persona = Usuario::factory()->create(['tipo_personal' => 'administrativo']);
        Legajo::factory()->create(['usuario_id' => $this->persona->id]);
    }

    private function url(string $id = ''): string
    {
        return '/api/v1/expedientes/'.$this->persona->id.'/vinculos-laborales'.($id ? '/'.$id : '');
    }

    private function datos(array $cambios = []): array
    {
        return ['tipo_personal' => 'administrativo', 'regimen_normativo_id' => RegimenNormativo::query()->where('codigo', '1057')->value('id'),
            'regimen_laboral' => 'plazo_determinado', 'modalidad_ingreso' => 'concurso_publico', 'nombre_puesto' => 'Jefe de oficina', 'fecha_inicio' => '2026-10-01',
            'numero_resolucion' => 'R-015-2026', 'fecha_resolucion' => '2026-09-25',
            'numero_contrato' => 'CAS-028-2026', 'fecha_contrato' => '2026-09-26',
            'proceso_seleccion' => 'CAS-001-2026', 'anio_proceso' => 2026, ...$cambios];
    }

    public function test_resolution_and_contract_have_independent_metadata_and_pdfs_on_the_same_cas_link(): void
    {
        $id = $this->postJson($this->url(), $this->datos())->assertCreated()
            ->assertJsonPath('data.numero_resolucion', 'R-015-2026')->assertJsonPath('data.numero_contrato', 'CAS-028-2026')->json('data.id');
        $resolucion = $this->subir($this->persona, $this->metadatos($this->tipo02('c'), 1, 2, [
            'enlace_id' => $id, 'num_resolucion' => 'R-015-2026', 'fecha_emision' => '2026-09-25',
        ]))->assertCreated()->json('data.id');
        $contrato = $this->subir($this->persona, $this->metadatos($this->tipo02('f'), 3, 4, [
            'enlace_id' => $id, 'num_resolucion' => 'CAS-028-2026', 'fecha_emision' => '2026-09-26',
        ]))->assertCreated()->json('data.id');

        $this->assertDatabaseHas('documentos_usuario', ['id' => $resolucion, 'vinculo_laboral_id' => $id, 'num_resolucion' => 'R-015-2026']);
        $this->assertDatabaseHas('documentos_usuario', ['id' => $contrato, 'vinculo_laboral_id' => $id, 'num_resolucion' => 'CAS-028-2026']);
        $this->assertNotSame(DocumentoUsuario::findOrFail($resolucion)->archivo_id, DocumentoUsuario::findOrFail($contrato)->archivo_id);
        $this->getJson('/api/v1/expedientes/'.$this->persona->id.'/seccion-02')->assertOk()
            ->assertJsonPath('data.vinculos.0.values.numero_resolucion', 'R-015-2026')->assertJsonPath('data.vinculos.0.values.fecha_contrato', '2026-09-26')
            ->assertJsonPath('data.vinculos.0.values.anio_proceso', 2026);
    }

    public function test_partial_corrections_keep_the_other_document_and_validate_dates_against_the_stored_number(): void
    {
        $id = $this->postJson($this->url(), $this->datos())->assertCreated()->json('data.id');
        $this->patchJson($this->url($id), ['numero_resolucion' => 'R-CORREGIDA'])->assertOk()->assertJsonPath('data.numero_contrato', 'CAS-028-2026');
        $this->patchJson($this->url($id), ['numero_contrato' => null])->assertUnprocessable()->assertJsonValidationErrors('numero_contrato');
        $this->patchJson($this->url($id), ['fecha_contrato' => '2026-09-27'])->assertOk();
        $this->patchJson($this->url($id), ['proceso_seleccion' => null])->assertUnprocessable()->assertJsonValidationErrors('proceso_seleccion');
        $this->patchJson($this->url($id), ['numero_contrato' => null, 'fecha_contrato' => null])->assertUnprocessable()->assertJsonValidationErrors('numero_contrato');
        $this->patchJson($this->url($id), ['fecha_contrato' => '2026-09-27'])->assertOk();
    }

    public function test_invalid_document_and_process_fields_are_rejected_without_creating_a_link(): void
    {
        foreach ([['numero_resolucion' => null], ['numero_contrato' => null], ['fecha_resolucion' => '2026-02-30'],
            ['fecha_contrato' => '2026-10-22'], ['numero_contrato' => str_repeat('A', 61)], ['anio_proceso' => 1800], ['anio_proceso' => 2025], ['proceso_seleccion' => null]] as $cambios) {
            $this->postJson($this->url(), $this->datos($cambios))->assertUnprocessable();
        }
        $this->assertDatabaseCount('vinculos_laborales', 0);
    }

    public function test_existing_ambiguous_numbers_are_preserved_without_guessing_document_type(): void
    {
        $vinculo = VinculoLaboral::factory()->create(['usuario_id' => $this->persona->id, 'numero_documento_vinculo' => 'ANTERIOR-001']);
        $this->patchJson($this->url($vinculo->id), ['nombre_puesto' => 'Puesto corregido'])->assertOk()
            ->assertJsonPath('data.numero_documento_vinculo', 'ANTERIOR-001')->assertJsonPath('data.numero_resolucion', null)->assertJsonPath('data.numero_contrato', null);
        $this->assertSame('ANTERIOR-001', $vinculo->refresh()->referenciaDocumentos());
        $this->patchJson($this->url($vinculo->id), ['numero_contrato' => 'CONTRATO-IDENTIFICADO'])->assertOk();
        $this->assertSame('ANTERIOR-001', $vinculo->refresh()->numero_documento_vinculo);
        $this->assertSame('CONTRATO-IDENTIFICADO', $vinculo->referenciaDocumentos());
    }

    public function test_teacher_confidence_is_rejected_on_creation_and_partial_correction(): void
    {
        $ley = RegimenNormativo::query()->where('codigo', '30220')->value('id');
        $datos = $this->datos(['tipo_personal' => 'docente', 'regimen_normativo_id' => $ley, 'regimen_laboral' => 'designado']);
        $this->postJson($this->url(), $datos)->assertUnprocessable()->assertJsonValidationErrors('regimen_laboral');
        $id = $this->postJson($this->url(), [...$datos, 'regimen_laboral' => 'carrera'])->assertCreated()->json('data.id');
        $this->patchJson($this->url($id), ['regimen_laboral' => 'designado'])->assertUnprocessable()->assertJsonValidationErrors('regimen_laboral');
        $this->patchJson($this->url($id), ['regimen_laboral' => 'plazo_determinado'])->assertOk();
    }

    public function test_a_closed_teacher_link_cannot_be_completed_as_confidence_or_have_its_documents_corrected(): void
    {
        $vinculo = VinculoLaboral::factory()->create(['usuario_id' => $this->persona->id, 'tipo_personal' => 'docente',
            'regimen_laboral' => null, 'fecha_inicio' => '2026-09-01', 'fecha_cese' => '2026-09-30']);
        $this->postJson($this->url($vinculo->id).'/completar-tipo', ['regimen_laboral' => 'designado'])->assertUnprocessable()->assertJsonValidationErrors('regimen_laboral');
        $this->patchJson($this->url($vinculo->id), ['numero_contrato' => 'NUEVO'])->assertConflict();
        $this->assertNull($vinculo->refresh()->regimen_laboral);
    }

    public function test_manual_registration_uses_the_same_document_fields_and_teacher_restriction(): void
    {
        $persona = ['numero_doc_identidad' => '71000001', 'nombres' => 'Rosa', 'apellido_paterno' => 'Registro',
            'email_institucional' => 'rosa.registro@example.test', 'tipo_personal' => 'docente',
            'regimen_normativo_id' => RegimenNormativo::query()->where('codigo', '30220')->value('id')];
        $vinculo = array_diff_key($this->datos(), array_flip(['tipo_personal', 'regimen_normativo_id']));
        $vinculo['regimen_laboral'] = 'designado';
        $datos = ['usuarios' => [$persona], 'vinculo' => $vinculo, 'datos_puesto' => ['condicion_docente' => 'contratado']];
        $this->postJson('/api/v1/usuarios/con-vinculo', $datos)->assertUnprocessable()->assertJsonValidationErrors('vinculo.regimen_laboral');
        $this->assertDatabaseMissing('usuarios', ['numero_doc_identidad' => '71000001']);
        $datos['vinculo']['regimen_laboral'] = 'plazo_determinado';
        $respuesta = $this->postJson('/api/v1/usuarios/con-vinculo', $datos)->assertCreated();
        $this->assertDatabaseHas('vinculos_laborales', ['id' => $respuesta->json('data.vinculo.id'),
            'numero_resolucion' => 'R-015-2026', 'numero_contrato' => 'CAS-028-2026', 'anio_proceso' => 2026]);
    }

    public function test_one_official_process_can_be_referenced_by_several_links_and_legacy_formats_are_kept(): void
    {
        $this->postJson($this->url(), $this->datos())->assertCreated();
        $this->postJson($this->url(), $this->datos(['nombre_puesto' => 'Otro puesto']))->assertCreated();
        $this->postJson($this->url(), $this->datos(['proceso_seleccion' => 'CONCURSO 01 / RRHH', 'anio_proceso' => 2026]))->assertCreated();
        $this->assertDatabaseCount('vinculos_laborales', 3);
    }

    public function test_other_sections_and_reports_keep_both_document_references(): void
    {
        $this->postJson($this->url(), $this->datos())->assertCreated();
        $this->getJson('/api/v1/expedientes/'.$this->persona->id.'/seccion-05')->assertOk()
            ->assertJsonPath('data.vinculos.0.numero_resolucion', 'R-015-2026')->assertJsonPath('data.vinculos.0.numero_contrato', 'CAS-028-2026');
        $this->getJson('/api/v1/expedientes/'.$this->persona->id.'/seccion-11')->assertOk()
            ->assertJsonPath('data.vinculos.0.values.numero_resolucion', 'R-015-2026')->assertJsonPath('data.vinculos.0.values.numero_contrato', 'CAS-028-2026');
        $this->getJson('/api/v1/reportes/contratos')->assertOk()->assertJsonPath('data.0.numero', 'R-015-2026 · CAS-028-2026');
        $this->getJson('/api/v1/reportes/movimientos?estado=ingreso')->assertOk()->assertJsonPath('data.0.numero', 'R-015-2026 · CAS-028-2026');
    }

    public function test_the_teaching_resolution_pdf_is_linked_to_its_teaching_admission(): void
    {
        $ley = RegimenNormativo::query()->where('codigo', '30220')->value('id');
        $this->persona->update(['tipo_personal' => 'docente', 'regimen_normativo_id' => $ley]);
        $id = $this->postJson($this->url(), $this->datos(['tipo_personal' => 'docente', 'regimen_normativo_id' => $ley,
            'regimen_laboral' => 'carrera', 'proceso_seleccion' => 'DOCENTE-001-2026', 'numero_contrato' => null, 'fecha_contrato' => null]))->assertCreated()->json('data.id');
        $tipo = TipoDocumento::query()->where('codigo', Seccion02CatalogoSeeder::RESOLUCION_DOCENTE)->where('activo', true)->sole();
        $this->assertSame('docente', $tipo->aplica_tipo_personal);
        $documento = $this->subir($this->persona, $this->metadatos($tipo, 1, 2, ['enlace_id' => $id, 'num_resolucion' => 'R-015-2026', 'fecha_emision' => '2026-09-25']))->assertCreated()->json('data.id');
        $this->assertDatabaseHas('documentos_usuario', ['id' => $documento, 'vinculo_laboral_id' => $id, 'tipo_documento_id' => $tipo->id]);
    }
}
