<?php

namespace Tests\Feature;

use App\Models\AuditoriaEvento;
use App\Models\DocumentoUsuario;
use App\Models\Legajo;
use App\Models\RegistroSstBienestar;
use App\Models\Rol;
use App\Models\SubseccionLegajo;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Support\ResumenSeccion10;
use Database\Seeders\Seccion10CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\SubeDocumentos;
use Tests\TestCase;

class Seccion10ApiTest extends TestCase
{
    use RefreshDatabase;
    use SubeDocumentos;

    private Usuario $persona;

    private Legajo $legajo;

    private Usuario $autor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-06 12:00:00', 'America/Lima'));
        Storage::fake(config('legajo.disco_documentos'));
        $this->seed(Seccion10CatalogoSeeder::class);
        $this->persona = Usuario::factory()->create();
        $this->legajo = Legajo::factory()->create(['usuario_id' => $this->persona->id]);
        $this->autor = $this->actuarComo();
    }

    private function url(string $sufijo = ''): string
    {
        return '/api/v1/expedientes/'.$this->persona->id.'/seccion-10'.$sufijo;
    }

    private function datos(string $codigo = 'S10_REGLAMENTO', array $cambios = []): array
    {
        $datos = ['nombre' => 'Evidencia administrativa', 'emisor' => 'Oficina de Recursos Humanos', 'fecha_emision' => '2026-10-01',
            'modalidad_presentacion' => 'copia_simple', 'numero_documento' => 'DOC-001', 'descripcion' => 'Constancia de participación.'];
        if (in_array($codigo, ['S10_DESCANSO_MEDICO', 'S10_EPS'], true)) {
            $datos += ['fecha_inicio' => '2026-10-01', 'fecha_fin' => '2026-10-03'];
        }
        if ($codigo === 'S10_EPS') {
            $datos += ['entidad' => 'EPS de prueba', 'fecha_fin' => null];
            $datos['fecha_fin'] = $cambios['fecha_fin'] ?? null;
        }

        return [...$datos, ...$cambios];
    }

    private function cargar(string $codigo = 'S10_REGLAMENTO', array $cambios = []): TestResponse
    {
        $tipo = TipoDocumento::query()->where('seccion_legajo_id', 10)->where('codigo', $codigo)->firstOrFail();
        $datos = $this->datos($codigo, ['tipo_documento_id' => $tipo->id, 'numero_folios' => 2, 'pdf' => $this->pdf(), ...$cambios]);

        return $this->post($this->url('/documentos'), $datos, ['Accept' => 'application/json']);
    }

    private function item(string $codigo): array
    {
        return collect($this->getJson($this->url())->assertOk()->json('data.items'))->firstWhere('codigo', $codigo);
    }

    public function test_catalogue_has_two_subsections_nine_types_and_real_empty_states(): void
    {
        $this->seed(Seccion10CatalogoSeeder::class);
        $this->assertSame(2, SubseccionLegajo::query()->where('seccion_legajo_id', 10)->count());
        $this->assertSame(9, TipoDocumento::query()->where('seccion_legajo_id', 10)->count());
        $data = $this->getJson($this->url())->assertOk()->assertJsonPath('data.folios.total', 0)->json('data');
        $this->assertCount(9, $data['items']);
        $this->assertSame($this->persona->id, $data['persona']['id']);
        $this->assertSame($this->persona->numero_doc_identidad, $data['persona']['numero_doc_identidad']);
        foreach ($data['items'] as $item) {
            $this->assertSame('no', $item['estado']);
            $this->assertSame(0, $item['cantidad']);
        }
    }

    public static function conceptos(): array
    {
        return array_map(fn (string $codigo): array => [$codigo], array_keys(ResumenSeccion10::TIPOS));
    }

    public function test_subsection_queries_separate_sst_and_welfare_and_keep_section_totals(): void
    {
        $this->cargar('S10_CAPACITACIONES', ['numero_folios' => 3])->assertCreated();
        $this->cargar('S10_SUBSIDIOS', ['numero_folios' => 2])->assertCreated();
        foreach (['10.1' => 5, '10.2' => 4] as $subseccion => $cantidad) {
            $data = $this->getJson($this->url('?subseccion='.$subseccion))->assertOk()
                ->assertJsonPath('data.subseccion', $subseccion)->assertJsonCount($cantidad, 'data.items')
                ->assertJsonPath('data.folios.sst', 3)->assertJsonPath('data.folios.bienestar', 2)->assertJsonPath('data.folios.total', 5)->json('data');
            foreach ($data['items'] as $item) {
                $this->assertSame($subseccion, $item['subseccion']);
                $this->assertNotSame('S10_OTROS', $item['codigo']);
            }
        }
        $this->getJson($this->url('?subseccion=10.3'))->assertUnprocessable();
    }

    public function test_retired_type_cannot_receive_new_uploads_and_preserves_existing_documents_and_folios(): void
    {
        $subseccion = SubseccionLegajo::query()->where('seccion_legajo_id', 10)->where('codigo', '10.2')->firstOrFail();
        $tipo = TipoDocumento::factory()->create(['seccion_legajo_id' => 10, 'subseccion_legajo_id' => $subseccion->id, 'codigo' => 'S10_OTROS', 'activo' => true]);
        $doc = DocumentoUsuario::factory()->create(['usuario_id' => $this->persona->id, 'legajo_id' => $this->legajo->id,
            'seccion_legajo_id' => 10, 'tipo_documento_id' => $tipo->id, 'estado' => 'cargado', 'archivo_id' => null,
            'folio_inicio' => 1, 'folio_fin' => 3, 'created_by' => $this->autor->id]);
        RegistroSstBienestar::factory()->create(['usuario_id' => $this->persona->id, 'documento_id' => $doc->id, 'tipo' => 'S10_OTROS', 'anulado' => false]);
        $this->seed(Seccion10CatalogoSeeder::class);
        $this->assertFalse($tipo->fresh()->activo);
        $this->cargar('S10_OTROS')->assertUnprocessable();
        $this->getJson($this->url('?subseccion=10.2'))->assertOk()->assertJsonCount(4, 'data.items')
            ->assertJsonPath('data.documentos_anteriores.0.id', $doc->id)->assertJsonPath('data.folios.bienestar', 3)->assertJsonPath('data.folios.total', 3);
        $this->getJson($this->url('?subseccion=10.1'))->assertOk()->assertJsonCount(0, 'data.documentos_anteriores');
        $this->getJson($this->url('/documentos/'.$doc->id.'/historial'))->assertOk()->assertJsonPath('data.documento.id', $doc->id);
        $this->assertDatabaseHas('documentos_usuario', ['id' => $doc->id]);
    }

    public function test_opening_legajo_then_uploading_from_both_subsections_uses_complete_base_catalogue(): void
    {
        $this->persona = Usuario::factory()->create();
        $this->getJson($this->url('?subseccion=10.1'))->assertOk()->assertJsonPath('data.legajo', null);
        $legajo = $this->postJson('/api/v1/expedientes/'.$this->persona->id.'/legajo')->assertCreated()->assertJsonPath('data.secciones', 13)->json('data.id');
        $this->legajo = Legajo::query()->findOrFail($legajo);
        $this->cargar('S10_REGLAMENTO')->assertCreated();
        $this->cargar('S10_SEGURO')->assertCreated();
        foreach (['10.1', '10.2'] as $subseccion) {
            $this->getJson($this->url('?subseccion='.$subseccion))->assertOk()->assertJsonPath('data.legajo.id', $legajo)
                ->assertJsonPath('data.folios.sst', 2)->assertJsonPath('data.folios.bienestar', 2)->assertJsonPath('data.folios.total', 4);
        }
        $this->assertCount(2, Storage::disk(config('legajo.disco_documentos'))->allFiles());
        $this->postJson('/api/v1/expedientes/'.$this->persona->id.'/legajo')->assertConflict();
    }

    #[DataProvider('conceptos')]
    public function test_every_document_type_uses_existing_private_file_storage_and_person_relation(string $codigo): void
    {
        $id = $this->cargar($codigo)->assertCreated()->json('data.id');
        $doc = DocumentoUsuario::query()->with('archivo')->findOrFail($id);
        $this->assertSame($this->persona->id, $doc->usuario_id);
        $this->assertSame($this->legajo->id, $doc->legajo_id);
        $this->assertSame(10, $doc->seccion_legajo_id);
        $this->assertSame($this->autor->id, $doc->created_by);
        $this->assertSame('documento.pdf', $doc->archivo->nombre_original);
        $this->assertSame('application/pdf', $doc->archivo->mime_type);
        Storage::disk(config('legajo.disco_documentos'))->assertExists($doc->archivo->storage_path);
        $this->assertDatabaseHas('registros_sst_bienestar', ['usuario_id' => $this->persona->id, 'documento_id' => $id, 'tipo' => $codigo, 'registrado_por' => $this->autor->id]);
        $item = $this->item($codigo);
        $this->assertSame('si', $item['estado']);
        $this->assertSame(1, $item['cantidad']);
        $this->assertSame(2, $item['numero_folios']);
        $this->assertSame('2026-10-01', $item['ultima_fecha']);
        $this->assertDatabaseHas('auditoria_eventos', ['accion' => 'CREAR_DOCUMENTO', 'entidad_id' => $id, 'actor_usuario_id' => $this->autor->id]);
        $this->assertDatabaseHas('auditoria_eventos', ['accion' => 'ADJUNTAR_ARCHIVO', 'entidad_id' => $id]);
    }

    public function test_pending_process_can_be_completed_without_overwriting_history_or_allocating_empty_folios(): void
    {
        $id = $this->cargar(cambios: ['pdf' => null, 'numero_folios' => null])->assertCreated()->assertJsonPath('data.estado', 'pendiente')->json('data.id');
        $this->assertDatabaseCount('archivos_documento', 0);
        $this->assertSame('pendiente', $this->item('S10_REGLAMENTO')['estado']);
        $this->getJson($this->url())->assertJsonPath('data.folios.total', 0)->assertJsonPath('data.folio_sugerido', 1);
        $this->patchJson($this->url('/documentos/'.$id), $this->datos(cambios: ['estado_anterior' => 'pendiente', 'nombre' => 'Constancia pendiente', 'modalidad_presentacion' => null]))
            ->assertOk()->assertJsonPath('data.estado', 'pendiente');
        $this->assertDatabaseCount('documentos_usuario', 1);
        $nuevo = $this->cargar(cambios: ['subsana_a_documento_id' => $id])->assertCreated()->json('data.id');
        $this->assertSame('si', $this->item('S10_REGLAMENTO')['estado']);
        $this->getJson($this->url())->assertJsonPath('data.folios.total', 2)->assertJsonPath('data.folio_sugerido', 3);
        $this->assertDatabaseHas('documentos_usuario', ['id' => $id, 'archivo_id' => null, 'estado' => 'pendiente']);
        $this->assertDatabaseHas('documentos_usuario', ['id' => $nuevo, 'subsana_a_documento_id' => $id]);
        $this->assertDatabaseHas('auditoria_eventos', ['entidad_id' => $nuevo, 'accion' => 'COMPLETAR_TRAMITE']);
    }

    public function test_missing_pdf_automatically_creates_pending_records_in_both_subsections_without_file_metadata(): void
    {
        foreach (['S10_REGLAMENTO', 'S10_SEGURO'] as $codigo) {
            $id = $this->cargar($codigo, ['pdf' => null, 'numero_folios' => null, 'modalidad_presentacion' => null, 'pendiente' => 0])
                ->assertCreated()->assertJsonPath('data.estado', 'pendiente')->assertJsonPath('data.archivo_id', null)->json('data.id');
            $this->assertSame('pendiente', $this->item($codigo)['estado']);
            $this->assertDatabaseHas('documentos_usuario', ['id' => $id, 'folio_inicio' => null, 'folio_fin' => null]);
        }
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.folios.total', 0)->assertJsonPath('data.folio_sugerido', 1);
        $this->assertDatabaseCount('archivos_documento', 0);
        $this->assertCount(0, Storage::disk(config('legajo.disco_documentos'))->allFiles());
    }

    public function test_real_pdf_cannot_be_marked_pending_by_a_client_flag(): void
    {
        $this->cargar(cambios: ['pendiente' => 1])->assertCreated()->assertJsonPath('data.estado', 'cargado');
        $this->assertSame('si', $this->item('S10_REGLAMENTO')['estado']);
    }

    public function test_folios_are_summed_by_subsection_excluding_annulled_and_superseded_documents(): void
    {
        $viejo = $this->cargar(cambios: ['numero_folios' => 3])->assertCreated()->json('data.id');
        $this->cargar(cambios: ['subsana_a_documento_id' => $viejo, 'numero_folios' => 5])->assertCreated();
        $bienestar = $this->cargar('S10_SEGURO', ['numero_folios' => 4])->assertCreated()->json('data.id');
        $this->getJson($this->url())->assertJsonPath('data.folios.sst', 5)->assertJsonPath('data.folios.bienestar', 4)
            ->assertJsonPath('data.folios.total', 9)->assertJsonPath('data.folio_sugerido', 13);
        $this->postJson($this->url('/documentos/'.$bienestar.'/anular'), ['estado_anterior' => 'cargado', 'motivo' => 'Registro duplicado.'])->assertOk();
        $this->getJson($this->url())->assertJsonPath('data.folios.total', 5)->assertJsonPath('data.folio_sugerido', 13);
        $this->assertSame('no', $this->item('S10_SEGURO')['estado']);
        $this->assertDatabaseHas('registros_sst_bienestar', ['documento_id' => $bienestar, 'anulado' => true, 'motivo_anulacion' => 'Registro duplicado.']);
        $this->assertDatabaseCount('documentos_usuario', 3);
        $this->assertCount(3, Storage::disk(config('legajo.disco_documentos'))->allFiles());
        $this->assertDatabaseHas('auditoria_eventos', ['entidad_id' => $viejo, 'accion' => 'DOCUMENTO_SUBSANADO']);
        $this->postJson($this->url('/documentos/'.$bienestar.'/anular'), ['estado_anterior' => 'cargado', 'motivo' => 'Otra anulación.'])->assertConflict();
    }

    public function test_expiry_and_eps_status_follow_dates_without_expiring_types_that_have_no_validity(): void
    {
        $this->cargar('S10_SEGURO', ['fecha_vencimiento' => '2026-10-05'])->assertCreated();
        $this->assertSame('vencido', $this->item('S10_SEGURO')['estado']);
        $this->cargar('S10_SEGURO', ['fecha_vencimiento' => '2026-10-06'])->assertCreated();
        $this->assertSame('si', $this->item('S10_SEGURO')['estado']);
        $this->cargar('S10_EPS', ['fecha_fin' => '2026-10-05'])->assertCreated();
        $eps = $this->item('S10_EPS');
        $this->assertSame('vencido', $eps['estado']);
        $this->assertSame('vencido', $eps['documentos'][0]['estado_eps']);
        $this->cargar('S10_DESCANSO_MEDICO')->assertCreated();
        $this->assertSame('si', $this->item('S10_DESCANSO_MEDICO')['estado']);
        $this->assertSame(3, $this->item('S10_DESCANSO_MEDICO')['documentos'][0]['numero_dias']);
        $this->cargar(cambios: ['fecha_vencimiento' => '2026-10-05'])->assertUnprocessable();
    }

    public function test_future_eps_affiliation_remains_pending_until_start_date(): void
    {
        $this->cargar('S10_EPS', ['fecha_inicio' => '2026-10-07', 'fecha_fin' => null])->assertCreated();
        $this->assertSame('pendiente', $this->item('S10_EPS')['estado']);
        $this->assertSame('pendiente', $this->item('S10_EPS')['documentos'][0]['estado_eps']);
        $this->travelTo(Carbon::parse('2026-10-07 12:00:00', 'America/Lima'));
        $this->assertSame('si', $this->item('S10_EPS')['estado']);
    }

    public function test_metadata_edits_are_audited_and_validated_evidence_requires_a_new_version(): void
    {
        $id = $this->cargar('S10_DESCANSO_MEDICO')->assertCreated()->json('data.id');
        $this->patchJson($this->url('/documentos/'.$id), $this->datos('S10_DESCANSO_MEDICO', ['estado_anterior' => 'cargado', 'fecha_fin' => '2026-10-04']))->assertOk();
        $this->assertSame(4, $this->item('S10_DESCANSO_MEDICO')['documentos'][0]['numero_dias']);
        $evento = AuditoriaEvento::query()->where('entidad_id', $id)->where('accion', 'ACTUALIZAR_DOCUMENTO')->firstOrFail();
        $this->assertSame('2026-10-03', Carbon::parse(json_decode($evento->datos_antes, true)['registro']['fecha_fin'])->toDateString());
        $this->assertSame('2026-10-04', Carbon::parse(json_decode($evento->datos_despues, true)['registro']['fecha_fin'])->toDateString());
        $this->postJson($this->url('/documentos/'.$id.'/estado'), ['estado' => 'aceptado', 'estado_anterior' => 'cargado', 'recepcion_confirmada' => true])->assertForbidden();
        $this->actuarComo();
        $this->postJson($this->url('/documentos/'.$id.'/estado'), ['estado' => 'aceptado', 'estado_anterior' => 'cargado', 'recepcion_confirmada' => true])->assertOk();
        $this->patchJson($this->url('/documentos/'.$id), $this->datos('S10_DESCANSO_MEDICO', ['estado_anterior' => 'aceptado', 'nombre' => 'Alterado']))->assertConflict();
        $nuevo = $this->cargar('S10_DESCANSO_MEDICO', ['subsana_a_documento_id' => $id])->assertCreated()->json('data.id');
        $this->assertDatabaseHas('documentos_usuario', ['id' => $id, 'estado' => 'aceptado', 'nombre' => 'Evidencia administrativa']);
        $this->assertDatabaseHas('auditoria_eventos', ['entidad_id' => $nuevo, 'accion' => 'REEMPLAZAR_ARCHIVO']);
    }

    public function test_observed_document_is_pending_and_absent_or_infected_files_cannot_be_accepted(): void
    {
        $id = $this->cargar()->assertCreated()->json('data.id');
        $this->actuarComo();
        $this->postJson($this->url('/documentos/'.$id.'/estado'), ['estado' => 'observado', 'estado_anterior' => 'cargado',
            'descripcion' => 'La constancia está incompleta.', 'plazo_subsanacion' => '2026-10-10'])->assertOk();
        $this->assertSame('pendiente', $this->item('S10_REGLAMENTO')['estado']);
        $otro = $this->cargar('S10_SIMULACROS')->assertCreated()->json('data.id');
        $doc = DocumentoUsuario::query()->with('archivo')->findOrFail($otro);
        $doc->archivo->update(['estado_antivirus' => 'infectado']);
        $this->actuarComo();
        $this->postJson($this->url('/documentos/'.$otro.'/estado'), ['estado' => 'aceptado', 'estado_anterior' => 'cargado', 'recepcion_confirmada' => true])->assertUnprocessable();
        $this->getJson('/api/v1/expedientes/'.$this->persona->id.'/documentos/'.$otro.'/archivo')->assertStatus(423);
        $doc->archivo->update(['estado_antivirus' => 'pendiente']);
        Storage::disk(config('legajo.disco_documentos'))->delete($doc->archivo->storage_path);
        $this->postJson($this->url('/documentos/'.$otro.'/estado'), ['estado' => 'aceptado', 'estado_anterior' => 'cargado', 'recepcion_confirmada' => true])->assertUnprocessable();
    }

    public function test_view_download_and_history_validate_person_and_record_actor_time_and_action(): void
    {
        $id = $this->cargar()->assertCreated()->json('data.id');
        $archivo = '/api/v1/expedientes/'.$this->persona->id.'/documentos/'.$id.'/archivo';
        $this->get($archivo.'?accion=descargar')->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertDatabaseHas('bitacora_accesos_legajo', ['documento_id' => $id, 'usuario_id' => $this->autor->id, 'accion' => 'descargar']);
        $this->assertDatabaseHas('auditoria_eventos', ['entidad_id' => $id, 'accion' => 'DESCARGAR_DOCUMENTO', 'usuario_titular_id' => $this->persona->id]);
        $this->getJson($this->url('/documentos/'.$id.'/historial'))->assertOk()->assertJsonCount(3, 'data.eventos')->assertJsonCount(1, 'data.accesos');
        $otro = Usuario::factory()->create();
        Legajo::factory()->create(['usuario_id' => $otro->id]);
        $base = '/api/v1/expedientes/'.$otro->id;
        $this->getJson($base.'/documentos/'.$id.'/archivo')->assertNotFound();
        $this->getJson($base.'/seccion-10/documentos/'.$id.'/historial')->assertNotFound();
        $this->patchJson($base.'/seccion-10/documentos/'.$id, $this->datos(cambios: ['estado_anterior' => 'cargado']))->assertNotFound();
        $this->postJson($base.'/seccion-10/documentos/'.$id.'/anular', ['estado_anterior' => 'cargado', 'motivo' => 'Manipulación de URL.'])->assertNotFound();
    }

    public function test_permissions_block_sensitive_access_and_allow_read_only_consultation(): void
    {
        $id = $this->cargar()->assertCreated()->json('data.id');
        $this->actuarComo(codigo: 'asistente_legajo');
        $this->getJson($this->url())->assertForbidden();
        $this->cargar()->assertForbidden();
        $this->getJson('/api/v1/expedientes/'.$this->persona->id.'/documentos/'.$id.'/archivo')->assertForbidden();
        $this->getJson($this->url('/documentos/'.$id.'/historial'))->assertForbidden();
        Rol::query()->where('codigo', 'ADMIN')->update(['ve_datos_sensibles' => false]);
        Sanctum::actingAs($this->autor->fresh());
        $this->getJson('/api/v1/registros-sst-bienestar')->assertForbidden();
        $this->getJson($this->url())->assertForbidden();
        config(['permisos.roles.consulta_sst' => ['legajo.ver']]);
        $lector = $this->asignarRol(Usuario::factory()->create(), 'consulta_sst', rol: ['ve_datos_sensibles' => true]);
        Sanctum::actingAs($lector->fresh());
        $this->getJson($this->url())->assertOk();
        $this->cargar()->assertForbidden();
        $this->patchJson($this->url('/documentos/'.$id), [])->assertForbidden();
        $this->postJson($this->url('/documentos/'.$id.'/anular'), [])->assertForbidden();
        $this->postJson($this->url('/documentos/'.$id.'/estado'), [])->assertForbidden();
    }

    public function test_invalid_payloads_medical_fields_spoofed_authors_bad_files_and_nonpositive_folios_are_rejected(): void
    {
        foreach ([['numero_folios' => 0], ['numero_folios' => -1], ['numero_folios' => 1.5], ['numero_folios' => 2147483648],
            ['nombre' => '   '], ['fecha_emision' => '2026-10-07'], ['fecha_emision' => '2026-02-30'],
            ['usuario_id' => $this->persona->id], ['registrado_por' => $this->autor->id], ['diagnostico' => 'Información clínica'], ['resultados_clinicos' => 'Resultado'],
            ['pdf' => UploadedFile::fake()->createWithContent('falso.pdf', 'Texto sin contenido PDF')]] as $datos) {
            $this->cargar(cambios: $datos)->assertUnprocessable();
        }
        $this->cargar('S10_DESCANSO_MEDICO', ['fecha_fin' => '2026-09-30'])->assertUnprocessable();
        $this->cargar('S10_EPS', ['entidad' => '  '])->assertUnprocessable();
        $this->cargar('S10_SEGURO', ['fecha_vencimiento' => '2026-09-30'])->assertUnprocessable();
        config(['legajo.tamano_maximo_pdf_kb' => 1]);
        $this->cargar(cambios: ['pdf' => $this->pdf(relleno: 2048)])->assertUnprocessable();
        $this->assertDatabaseCount('registros_sst_bienestar', 0);
        $this->assertCount(0, Storage::disk(config('legajo.disco_documentos'))->allFiles());
    }

    public function test_invalid_person_closed_legajo_wrong_type_or_cross_person_replacement_cannot_create_documents(): void
    {
        $tipo = TipoDocumento::query()->where('codigo', 'S10_REGLAMENTO')->firstOrFail();
        $this->postJson('/api/v1/expedientes/00000000-0000-4000-8000-000000000000/seccion-10/documentos', [])->assertNotFound();
        $this->legajo->update(['estado_legajo' => 'cerrado']);
        $this->cargar()->assertConflict();
        $this->legajo->update(['estado_legajo' => 'activo']);
        $invalido = TipoDocumento::factory()->create(['seccion_legajo_id' => 10]);
        $this->cargar(cambios: ['tipo_documento_id' => $invalido->id])->assertUnprocessable();
        $ajeno = DocumentoUsuario::factory()->create(['tipo_documento_id' => $tipo->id, 'seccion_legajo_id' => 10]);
        $this->cargar(cambios: ['subsana_a_documento_id' => $ajeno->id])->assertUnprocessable();
        $id = $this->cargar()->assertCreated()->json('data.id');
        $this->cargar('S10_SEGURO', ['subsana_a_documento_id' => $id])->assertUnprocessable();
        $tipo->update(['activo' => false]);
        $this->cargar()->assertUnprocessable();
    }

    public function test_generic_routes_cannot_bypass_sst_validation_or_physically_delete_records(): void
    {
        $tipo = TipoDocumento::query()->where('codigo', 'S10_REGLAMENTO')->firstOrFail();
        $this->subir($this->persona, $this->metadatos($tipo, 1, 1))->assertUnprocessable();
        // En la base integrada la ruta genérica de escritura de documentos-usuario ya está cerrada (solo index y show):
        // el routing rechaza la solicitud con 405 antes de llegar al controlador.
        $this->postJson('/api/v1/documentos-usuario', ['tipo_documento_id' => $tipo->id, 'seccion_legajo_id' => 10])->assertStatus(405);
        $id = $this->cargar()->assertCreated()->json('data.id');
        $registro = RegistroSstBienestar::query()->where('documento_id', $id)->firstOrFail();
        $this->patchJson('/api/v1/registros-sst-bienestar/'.$registro->id, ['usuario_id' => $this->autor->id])->assertStatus(405);
        $this->deleteJson('/api/v1/registros-sst-bienestar/'.$registro->id)->assertStatus(405);
        $this->assertDatabaseHas('registros_sst_bienestar', ['id' => $registro->id, 'usuario_id' => $this->persona->id]);
        $evento = AuditoriaEvento::query()->where('entidad_id', $id)->firstOrFail();
        $this->deleteJson('/api/v1/auditoria-eventos/'.$evento->id)->assertConflict();
        $this->patchJson('/api/v1/auditoria-eventos/'.$evento->id, ['accion' => 'ALTERADO'])->assertConflict();
        $historial = DocumentoUsuario::query()->findOrFail($id)->historialEstadosDocumento()->firstOrFail();
        $this->deleteJson('/api/v1/historial-estados-documento/'.$historial->id)->assertConflict();
        Rol::query()->where('codigo', 'ADMIN')->update(['ve_datos_sensibles' => false]);
        Sanctum::actingAs($this->autor->fresh());
        $this->getJson('/api/v1/auditoria-eventos/'.$evento->id)->assertNotFound();
        $this->getJson('/api/v1/historial-estados-documento/'.$historial->id)->assertNotFound();
        $this->getJson('/api/v1/auditoria-eventos')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_failed_audit_rolls_back_registry_folios_document_and_uploaded_file(): void
    {
        Event::listen('eloquent.creating: '.AuditoriaEvento::class, function (): void {
            throw new \RuntimeException('Fallo de auditoría simulado.');
        });
        $this->withoutExceptionHandling();
        try {
            $this->cargar();
            $this->fail('Debía fallar la auditoría.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Fallo de auditoría simulado.', $exception->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.AuditoriaEvento::class);
        }
        $this->assertDatabaseCount('registros_sst_bienestar', 0);
        $this->assertDatabaseCount('archivos_documento', 0);
        $this->assertDatabaseCount('documentos_usuario', 0);
        $this->assertDatabaseMissing('legajo_secciones', ['legajo_id' => $this->legajo->id, 'seccion_legajo_id' => 10]);
        $this->assertCount(0, Storage::disk(config('legajo.disco_documentos'))->allFiles());
    }
}
