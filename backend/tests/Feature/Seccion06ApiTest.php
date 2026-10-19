<?php

namespace Tests\Feature;

use App\Models\AuditoriaEvento;
use App\Models\CompensacionBeneficio;
use App\Models\DocumentoUsuario;
use App\Models\Legajo;
use App\Models\Rol;
use App\Models\TiempoServicioReconocido;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Support\Documentos\AlmacenDocumento;
use Database\Seeders\Seccion06CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\SubeDocumentos;
use Tests\TestCase;

class Seccion06ApiTest extends TestCase
{
    use RefreshDatabase;
    use SubeDocumentos;

    private Usuario $persona;

    private Legajo $legajo;

    private Usuario $autor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-05 12:00:00'));
        Storage::fake(config('legajo.disco_documentos'));
        $this->seed(Seccion06CatalogoSeeder::class);
        $this->persona = Usuario::factory()->create(['tipo_personal' => 'docente', 'regimen_laboral' => 'carrera']);
        $this->legajo = Legajo::factory()->create(['usuario_id' => $this->persona->id]);
        $this->autor = $this->actuarComo();
    }

    private function url(string $sufijo = ''): string
    {
        return '/api/v1/expedientes/'.$this->persona->id.'/seccion-06'.$sufijo;
    }

    private function cargar(string $codigo = 'COMP_RECONOCIMIENTO_TIEMPO', array $cambios = []): TestResponse
    {
        $tipo = TipoDocumento::query()->where('codigo', $codigo)->firstOrFail();
        $folio = (int) DocumentoUsuario::query()->where('legajo_id', $this->legajo->id)->where('seccion_legajo_id', 6)->max('folio_fin') + 1;
        $datos = ['tipo_documento_id' => $tipo->id, 'nombre' => 'Documento de compensaciones', 'emisor' => 'Oficina de Recursos Humanos', 'fecha_emision' => '2026-10-01',
            'folio_inicio' => $folio, 'folio_fin' => $folio, 'modalidad_presentacion' => 'copia_simple', 'pdf' => $this->pdf(paginas: max(1, ($cambios['folio_fin'] ?? $folio) - ($cambios['folio_inicio'] ?? $folio) + 1))];
        if ($codigo === 'COMP_RECONOCIMIENTO_TIEMPO') {
            $datos += ['num_resolucion' => 'RES-001', 'anios' => 12, 'meses' => 3, 'dias' => 15];
        }
        if ($codigo === 'COMP_PAGO_COMPENSACION') {
            $datos += ['tipo' => 'economica', 'concepto' => 'Compensación registrada', 'fecha' => '2026-09-30', 'numero_documento' => 'PAGO-001'];
        }

        return $this->post($this->url('/documentos'), [...$datos, ...$cambios], ['Accept' => 'application/json']);
    }

    private function aceptar(string $id, array $cambios = []): TestResponse
    {
        return $this->postJson($this->url('/documentos/'.$id.'/estado'), ['estado' => 'aceptado', 'estado_anterior' => 'pendiente', 'recepcion_confirmada' => true, ...$cambios]);
    }

    public function test_catalog_preserves_existing_settings_and_has_no_universal_missing_documents(): void
    {
        TipoDocumento::query()->where('codigo', 'COMP_OTROS')->update(['nombre' => 'Nombre institucional']);
        $this->seed(Seccion06CatalogoSeeder::class);
        $this->assertSame(5, TipoDocumento::query()->where('seccion_legajo_id', 6)->count());
        $this->assertDatabaseHas('tipos_documento', ['codigo' => 'COMP_OTROS', 'nombre' => 'Nombre institucional', 'es_hook_universidad' => true]);
        $this->getJson($this->url())->assertOk()->assertJsonCount(5, 'data.items')->assertJsonPath('data.resumen.faltantes', 0)
            ->assertJsonPath('data.resumen.porcentaje', null)->assertJsonPath('data.resumen.por_determinar', 0)->assertJsonPath('data.items.0.estado', 'opcional');
        TipoDocumento::query()->where('codigo', 'COMP_RECONOCIMIENTO_TIEMPO')->update(['obligatorio' => true]);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.resumen.faltantes', 1);
    }

    public function test_recognition_upload_links_resolution_time_pdf_history_and_audit_atomically(): void
    {
        $id = $this->cargar()->assertCreated()->assertJsonPath('data.estado', 'pendiente')->json('data.id');
        $doc = DocumentoUsuario::query()->with('archivo')->findOrFail($id);
        $this->assertDatabaseHas('tiempo_servicios_reconocidos', ['documento_id' => $id, 'usuario_id' => $this->persona->id, 'registrado_por' => $this->autor->id,
            'numero_resolucion' => 'RES-001', 'anios' => 12, 'meses' => 3, 'dias' => 15]);
        $tiempo = TiempoServicioReconocido::query()->where('documento_id', $id)->firstOrFail();
        $this->assertSame($doc->fecha_emision->toDateString(), $tiempo->fecha_resolucion->toDateString());
        $this->assertDatabaseHas('historial_estados_documento', ['documento_usuario_id' => $id, 'estado_nuevo' => 'pendiente']);
        $this->assertDatabaseHas('auditoria_eventos', ['entidad_id' => $id, 'accion' => 'compensaciones.documento.creado']);
        $this->assertSame('limpio', $doc->archivo->estado_antivirus);
        $this->assertSame(hash('sha256', AlmacenDocumento::leer($doc->archivo)), $doc->archivo->hash_sha256);
        $this->assertSame($this->persona->numero_doc_identidad, $doc->titular_documento);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.items.0.documentos.0.tiempos_reconocidos.0.anios', 12)->assertJsonPath('data.resumen.pendientes', 1);
    }

    public function test_economic_and_non_economic_compensations_use_existing_table(): void
    {
        foreach (['economica', 'no_economica'] as $tipo) {
            $id = $this->cargar('COMP_PAGO_COMPENSACION', ['tipo' => $tipo])->assertCreated()->json('data.id');
            $this->assertDatabaseHas('compensaciones_beneficios', ['usuario_id' => $this->persona->id, 'documento_id' => $id, 'tipo' => $tipo, 'registrado_por' => $this->autor->id]);
        }
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.items.1.cantidad', 2)->assertJsonPath('data.resumen.pendientes', 2);
        $this->assertDatabaseCount('tiempo_servicios_reconocidos', 0);
    }

    public function test_retention_liquidation_and_hook_are_documentary_without_artificial_data(): void
    {
        foreach (['COMP_RETENCION_TRIBUTARIA', 'COMP_LIQUIDACION_BENEFICIOS', 'COMP_OTROS'] as $codigo) {
            $id = $this->cargar($codigo)->assertCreated()->json('data.id');
            $this->actuarComo();
            $this->aceptar($id)->assertOk();
        }
        $this->assertDatabaseCount('compensaciones_beneficios', 0);
        $this->assertDatabaseCount('tiempo_servicios_reconocidos', 0);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.resumen.aceptados', 3)->assertJsonPath('data.resumen.porcentaje', 100);
    }

    public function test_folios_reject_overlap_gaps_and_reversed_ranges_preserving_files(): void
    {
        $this->cargar()->assertCreated();
        foreach ([[1, 1], [3, 3], [2, 1]] as [$inicio, $fin]) {
            $this->cargar(cambios: ['folio_inicio' => $inicio, 'folio_fin' => $fin])->assertUnprocessable();
        }
        $this->cargar('COMP_OTROS', ['folio_inicio' => 2, 'folio_fin' => 4])->assertCreated();
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.numero_folios', 4)->assertJsonPath('data.folio_sugerido', 5);
        $this->assertDatabaseCount('archivos_documento', 2);
        $this->assertCount(2, Storage::disk(config('legajo.disco_documentos'))->allFiles());
    }

    public function test_invalid_specific_fields_dates_pdf_size_and_author_spoofing_are_rejected(): void
    {
        foreach ([['anios' => -1], ['meses' => -2], ['dias' => -1], ['dias' => 2147483648], ['anios' => 1.5], ['num_resolucion' => ''], ['fecha_emision' => '2027-01-01'],
            ['fecha_emision' => '2026-02-30'], ['nombre' => '   '], ['registrado_por' => $this->autor->id], ['usuario_id' => $this->persona->id]] as $datos) {
            $this->cargar(cambios: $datos)->assertUnprocessable();
        }
        foreach ([['tipo' => 'otra'], ['concepto' => '   '], ['fecha' => '2027-01-01'], ['anios' => 1]] as $datos) {
            $this->cargar('COMP_PAGO_COMPENSACION', $datos)->assertUnprocessable();
        }
        $this->cargar('COMP_RETENCION_TRIBUTARIA', ['monto' => 100])->assertUnprocessable();
        $this->cargar(cambios: ['pdf' => UploadedFile::fake()->createWithContent('falso.pdf', 'Texto')])->assertUnprocessable()->assertJsonValidationErrors('pdf');
        config(['legajo.tamano_maximo_pdf_kb' => 1]);
        $this->cargar(cambios: ['pdf' => $this->pdf(relleno: 2000)])->assertUnprocessable()->assertJsonValidationErrors('pdf');
        $this->assertDatabaseCount('documentos_usuario', 0);
        $this->assertCount(0, Storage::disk(config('legajo.disco_documentos'))->allFiles());
    }

    public function test_time_is_transcribed_without_administrative_conversion_or_accumulation(): void
    {
        $this->cargar(cambios: ['anios' => 0, 'meses' => 13, 'dias' => 31])->assertCreated();
        $this->cargar(cambios: ['num_resolucion' => 'RES-002', 'anios' => 0, 'meses' => 0, 'dias' => 0])->assertCreated();
        $this->assertDatabaseHas('tiempo_servicios_reconocidos', ['anios' => 0, 'meses' => 13, 'dias' => 31]);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.items.0.cantidad', 2);
        $this->assertDatabaseCount('experiencia_laboral', 0);
    }

    public function test_catalog_wrong_section_inactive_unknown_code_and_missing_legajo_are_rejected(): void
    {
        $tipo = TipoDocumento::factory()->create(['seccion_legajo_id' => 4]);
        $this->cargar(cambios: ['tipo_documento_id' => $tipo->id])->assertUnprocessable();
        TipoDocumento::query()->where('codigo', 'COMP_OTROS')->update(['activo' => false]);
        $this->cargar('COMP_OTROS')->assertUnprocessable();
        $tipo = TipoDocumento::factory()->create(['seccion_legajo_id' => 6, 'activo' => true]);
        $this->cargar(cambios: ['tipo_documento_id' => $tipo->id])->assertUnprocessable();
        $this->legajo->update(['estado_legajo' => 'cerrado']);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.legajo.id', $this->legajo->id)->assertJsonPath('data.legajo.estado_legajo', 'cerrado');
        $this->cargar()->assertConflict();
    }

    public function test_author_cannot_review_and_other_reviewer_must_confirm_receipt_and_fedateo(): void
    {
        TipoDocumento::query()->where('codigo', 'COMP_RECONOCIMIENTO_TIEMPO')->update(['requiere_fedateo' => true]);
        $id = $this->cargar()->assertCreated()->json('data.id');
        $this->aceptar($id)->assertForbidden();
        $this->actuarComo();
        $this->aceptar($id, ['recepcion_confirmada' => false])->assertUnprocessable()->assertJsonValidationErrors('recepcion_confirmada');
        $this->aceptar($id)->assertUnprocessable()->assertJsonValidationErrors('fedateado');
        $this->aceptar($id, ['fedateado' => true])->assertOk();
        $this->assertDatabaseHas('documentos_usuario', ['id' => $id, 'estado' => 'aceptado', 'fiscalizado' => false, 'fedateado' => true]);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.resumen.porcentaje', 100);
        $this->aceptar($id)->assertConflict();
    }

    public function test_observation_requires_reason_deadline_and_new_subsanation_before_acceptance(): void
    {
        $id = $this->cargar()->assertCreated()->json('data.id');
        $this->actuarComo();
        $url = $this->url('/documentos/'.$id.'/estado');
        $base = ['estado' => 'observado', 'estado_anterior' => 'pendiente'];
        $this->postJson($url, $base)->assertUnprocessable();
        $this->postJson($url, [...$base, 'descripcion' => '   ', 'plazo_subsanacion' => '2026-10-30'])->assertUnprocessable();
        $this->postJson($url, [...$base, 'descripcion' => 'Revisar tiempo', 'plazo_subsanacion' => '2026-10-04'])->assertUnprocessable();
        $this->postJson($url, [...$base, 'descripcion' => 'Revisar tiempo', 'plazo_subsanacion' => '2026-10-30'])->assertOk();
        $this->aceptar($id, ['estado_anterior' => 'observado'])->assertConflict();
        $nuevo = $this->cargar(cambios: ['subsana_a_documento_id' => $id, 'dias' => 16])->assertCreated()->json('data.id');
        $this->actuarComo();
        $this->aceptar($nuevo)->assertOk();
        $this->assertDatabaseHas('observaciones_documento', ['documento_id' => $id, 'estado' => 'subsanada', 'documento_subsanacion_id' => $nuevo]);
        $this->assertDatabaseCount('tiempo_servicios_reconocidos', 2);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.items.0.cantidad', 1)->assertJsonPath('data.resumen.aceptados', 1);
        $this->aceptar($id, ['estado_anterior' => 'observado'])->assertConflict();
    }

    public function test_annulment_preserves_evidence_does_not_reuse_folios_and_excludes_current_counts(): void
    {
        $id = $this->cargar()->assertCreated()->json('data.id');
        $this->actuarComo();
        $url = $this->url('/documentos/'.$id.'/estado');
        $this->postJson($url, ['estado' => 'anulado', 'estado_anterior' => 'pendiente'])->assertUnprocessable();
        $this->postJson($url, ['estado' => 'anulado', 'estado_anterior' => 'pendiente', 'descripcion' => 'Registrado por error'])->assertOk();
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.resumen.registrados', 0)->assertJsonPath('data.resumen.porcentaje', null)->assertJsonPath('data.folio_sugerido', 2);
        $this->cargar(cambios: ['subsana_a_documento_id' => $id])->assertUnprocessable();
        $this->cargar()->assertCreated();
        $this->assertDatabaseCount('documentos_usuario', 2);
        $this->assertDatabaseCount('tiempo_servicios_reconocidos', 2);
        $this->assertDatabaseHas('historial_estados_documento', ['documento_usuario_id' => $id, 'estado_nuevo' => 'anulado', 'motivo' => 'Registrado por error']);
        $this->assertCount(2, Storage::disk(config('legajo.disco_documentos'))->allFiles());
    }

    public function test_subsanation_rejects_wrong_type_owner_already_subsaned_and_single_type_without_subsanation(): void
    {
        $id = $this->cargar()->assertCreated()->json('data.id');
        $this->cargar('COMP_OTROS', ['subsana_a_documento_id' => $id])->assertUnprocessable();
        $otra = Usuario::factory()->create();
        $doc = DocumentoUsuario::factory()->create(['usuario_id' => $otra->id]);
        $this->cargar(cambios: ['subsana_a_documento_id' => $doc->id])->assertUnprocessable();
        TipoDocumento::query()->where('codigo', 'COMP_RECONOCIMIENTO_TIEMPO')->update(['permite_multiples' => false]);
        $this->cargar()->assertUnprocessable();
        $this->cargar(cambios: ['subsana_a_documento_id' => $id])->assertCreated();
        $this->cargar(cambios: ['subsana_a_documento_id' => $id])->assertUnprocessable();
    }

    public function test_read_review_and_file_queries_do_not_cross_legajos_or_persons(): void
    {
        $id = $this->cargar()->assertCreated()->json('data.id');
        $otra = Usuario::factory()->create();
        Legajo::factory()->create(['usuario_id' => $otra->id]);
        $this->getJson('/api/v1/expedientes/'.$otra->id.'/seccion-06')->assertOk()->assertJsonPath('data.resumen.registrados', 0);
        $this->actuarComo();
        $this->postJson('/api/v1/expedientes/'.$otra->id.'/seccion-06/documentos/'.$id.'/estado', ['estado' => 'aceptado', 'estado_anterior' => 'pendiente', 'recepcion_confirmada' => true])->assertNotFound();
        $this->getJson('/api/v1/expedientes/'.$otra->id.'/documentos/'.$id.'/archivo')->assertNotFound();
        $this->postJson('/api/v1/expedientes/'.$this->persona->id.'/seccion-04/documentos/'.$id.'/estado', ['estado' => 'aceptado', 'estado_anterior' => 'pendiente', 'recepcion_confirmada' => true])->assertNotFound();
    }

    public function test_missing_permission_blocks_read_upload_and_review_on_server(): void
    {
        $id = $this->cargar()->assertCreated()->json('data.id');
        $this->actuarComo(codigo: 'sin_permisos');
        $this->getJson($this->url())->assertForbidden();
        $this->cargar()->assertForbidden();
        $this->aceptar($id)->assertForbidden();
    }

    public function test_assistant_can_read_and_upload_non_sensitive_types_but_cannot_review(): void
    {
        $this->actuarComo(codigo: 'asistente_legajo');
        $id = $this->cargar()->assertCreated()->json('data.id');
        $this->getJson($this->url())->assertOk();
        $this->aceptar($id)->assertForbidden();
    }

    public function test_sensitive_documents_and_structured_data_are_hidden_from_all_person_queries(): void
    {
        $id = $this->cargar('COMP_PAGO_COMPENSACION')->assertCreated()->json('data.id');
        $this->actuarComo(codigo: 'asistente_legajo');
        $this->getJson($this->url())->assertOk()->assertJsonCount(2, 'data.items')->assertJsonPath('data.resumen.registrados', 0);
        $this->cargar('COMP_PAGO_COMPENSACION')->assertForbidden();
        $this->getJson('/api/v1/expedientes/'.$this->persona->id.'/documentos/'.$id.'/archivo')->assertForbidden();
        $this->getJson('/api/v1/expedientes/'.$this->persona->id.'/documentos-usuario?seccion_id=6')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/v1/expedientes/'.$this->persona->id.'/compensaciones-beneficios')->assertOk()->assertJsonPath('total', 0);
        TipoDocumento::query()->where('codigo', 'COMP_RECONOCIMIENTO_TIEMPO')->update(['es_dato_sensible' => true]);
        $this->actuarComo();
        $this->cargar()->assertCreated();
        $this->actuarComo(codigo: 'asistente_legajo');
        $this->getJson('/api/v1/expedientes/'.$this->persona->id.'/tiempo-servicios-reconocidos')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_infected_and_missing_files_block_acceptance_and_infected_visor(): void
    {
        $id = $this->cargar()->assertCreated()->json('data.id');
        $doc = DocumentoUsuario::query()->findOrFail($id);
        $doc->archivo->update(['estado_antivirus' => 'infectado']);
        $this->actuarComo();
        $this->aceptar($id)->assertUnprocessable();
        $this->getJson('/api/v1/expedientes/'.$this->persona->id.'/documentos/'.$id.'/archivo')->assertStatus(423);
        $doc->update(['archivo_id' => null]);
        $this->aceptar($id)->assertUnprocessable();
    }

    public function test_acceptance_rejects_inconsistent_structured_recognition_and_compensation(): void
    {
        $id = $this->cargar()->assertCreated()->json('data.id');
        TiempoServicioReconocido::query()->where('documento_id', $id)->update(['numero_resolucion' => 'Otra resolución']);
        $this->actuarComo();
        $this->aceptar($id)->assertUnprocessable();
        $id = $this->cargar('COMP_PAGO_COMPENSACION')->assertCreated()->json('data.id');
        CompensacionBeneficio::query()->where('documento_id', $id)->update(['usuario_id' => Usuario::factory()->create()->id]);
        $this->actuarComo();
        $this->aceptar($id)->assertUnprocessable();
    }

    public function test_generic_document_upload_cannot_bypass_section_data_and_evidence_is_immutable(): void
    {
        $tipo = TipoDocumento::query()->where('codigo', 'COMP_RECONOCIMIENTO_TIEMPO')->firstOrFail();
        $this->post('/api/v1/expedientes/'.$this->persona->id.'/documentos', $this->metadatos($tipo, 1, 1), ['Accept' => 'application/json'])->assertUnprocessable();
        // El CRUD genérico de documentos-usuario no tiene store (sección 02): responde 405.
        $this->postJson('/api/v1/documentos-usuario', ['tipo_documento_id' => $tipo->id])->assertStatus(405);
        $id = $this->cargar()->assertCreated()->json('data.id');
        $this->patchJson('/api/v1/documentos-usuario/'.$id, ['estado' => 'aceptado'])->assertStatus(405);
        $this->deleteJson('/api/v1/documentos-usuario/'.$id)->assertStatus(405);
    }

    #[DataProvider('structuredTypes')]
    public function test_generic_crud_cannot_update_delete_create_or_relink_sustained_data(string $codigo, string $clase, string $recurso): void
    {
        $id = $this->cargar($codigo)->assertCreated()->json('data.id');
        $registro = $clase::query()->where('documento_id', $id)->firstOrFail();
        $this->patchJson('/api/v1/'.$recurso.'/'.$registro->id, ['documento_id' => null])->assertConflict();
        $this->deleteJson('/api/v1/'.$recurso.'/'.$registro->id)->assertConflict();
        $this->postJson('/api/v1/'.$recurso, ['documento_id' => $id])->assertConflict();
        $libre = $clase::factory()->create();
        $this->patchJson('/api/v1/'.$recurso.'/'.$libre->id, ['documento_id' => $id])->assertConflict();
        $this->assertSame($id, $registro->fresh()->documento_id);
    }

    public static function structuredTypes(): array
    {
        return [
            ['COMP_RECONOCIMIENTO_TIEMPO', TiempoServicioReconocido::class, 'tiempo-servicios-reconocidos'],
            ['COMP_PAGO_COMPENSACION', CompensacionBeneficio::class, 'compensaciones-beneficios'],
        ];
    }

    public function test_audit_failure_rolls_back_structured_data_document_file_history_and_reserved_folios(): void
    {
        Event::listen('eloquent.creating: '.AuditoriaEvento::class, fn () => throw new \RuntimeException('Auditoría no disponible'));
        $this->withoutExceptionHandling();
        try {
            $this->cargar();
            $this->fail('La carga no debe confirmarse sin auditoría.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Auditoría no disponible', $e->getMessage());
        }
        $this->assertDatabaseCount('documentos_usuario', 0);
        $this->assertDatabaseCount('tiempo_servicios_reconocidos', 0);
        $this->assertDatabaseCount('legajo_secciones', 0);
        $this->assertDatabaseCount('historial_estados_documento', 0);
        $this->assertCount(0, Storage::disk(config('legajo.disco_documentos'))->allFiles());
    }

    public function test_catalog_rollback_keeps_custom_types_and_blocks_types_with_evidence(): void
    {
        TipoDocumento::query()->where('codigo', 'COMP_OTROS')->delete();
        $custom = TipoDocumento::factory()->create(['seccion_legajo_id' => 6, 'codigo' => 'COMP_OTROS']);
        $this->seed(Seccion06CatalogoSeeder::class);
        $migration = require database_path('migrations/2026_10_05_195437_seed_seccion06_catalogo.php');
        $migration->down();
        $this->assertDatabaseHas('tipos_documento', ['id' => $custom->id]);
        $migration->up();
        $this->cargar()->assertCreated();
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    public function test_sensitive_generic_admin_reads_do_not_bypass_sensitive_permission(): void
    {
        $id = $this->cargar('COMP_PAGO_COMPENSACION')->assertCreated()->json('data.id');
        $doc = DocumentoUsuario::query()->findOrFail($id);
        $registro = CompensacionBeneficio::query()->where('documento_id', $id)->firstOrFail();
        Rol::query()->where('codigo', 'ADMIN')->update(['ve_datos_sensibles' => false]);
        $this->actuarComo();
        $this->getJson('/api/v1/compensaciones-beneficios')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/compensaciones-beneficios/'.$registro->id)->assertNotFound();
        $this->patchJson('/api/v1/compensaciones-beneficios/'.$registro->id, ['concepto' => 'Cambio'])->assertNotFound();
        $this->deleteJson('/api/v1/compensaciones-beneficios/'.$registro->id)->assertNotFound();
        $this->getJson('/api/v1/documentos-usuario/'.$id)->assertNotFound();
        $this->getJson('/api/v1/archivos-documento/'.$doc->archivo_id)->assertNotFound();
        $this->aceptar($id)->assertForbidden();
    }

    public function test_generic_person_lists_exclude_annulled_and_subsaned_section06_data(): void
    {
        $id = $this->cargar()->assertCreated()->json('data.id');
        $nuevo = $this->cargar(cambios: ['subsana_a_documento_id' => $id])->assertCreated()->json('data.id');
        $base = '/api/v1/expedientes/'.$this->persona->id.'/tiempo-servicios-reconocidos';
        $this->getJson($base)->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', TiempoServicioReconocido::query()->where('documento_id', $nuevo)->firstOrFail()->id);
        $this->actuarComo();
        $this->postJson($this->url('/documentos/'.$nuevo.'/estado'), ['estado' => 'anulado', 'estado_anterior' => 'pendiente', 'descripcion' => 'No corresponde'])->assertOk();
        $this->getJson($base)->assertOk()->assertJsonPath('total', 0);
    }

    public function test_missing_physical_file_and_closed_legajo_block_review(): void
    {
        $id = $this->cargar()->assertCreated()->json('data.id');
        $doc = DocumentoUsuario::query()->findOrFail($id);
        Storage::disk(config('legajo.disco_documentos'))->delete($doc->archivo->storage_path);
        $this->actuarComo();
        $this->aceptar($id)->assertUnprocessable();
        $this->legajo->update(['estado_legajo' => 'cerrado']);
        $this->aceptar($id)->assertConflict();
        $this->assertDatabaseHas('documentos_usuario', ['id' => $id, 'estado' => 'pendiente']);
        $this->assertDatabaseCount('historial_estados_documento', 1);
    }

    public function test_stale_state_and_failed_audit_do_not_partially_accept_document(): void
    {
        $id = $this->cargar()->assertCreated()->json('data.id');
        $this->actuarComo();
        $this->aceptar($id, ['estado_anterior' => 'observado'])->assertConflict();
        Event::listen('eloquent.creating: '.AuditoriaEvento::class, fn () => throw new \RuntimeException('Auditoría no disponible'));
        $this->withoutExceptionHandling();
        try {
            $this->aceptar($id);
            $this->fail('La aceptación no debe confirmarse sin auditoría.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Auditoría no disponible', $e->getMessage());
        }
        $this->assertDatabaseHas('documentos_usuario', ['id' => $id, 'estado' => 'pendiente', 'fecha_recepcion' => null]);
        $this->assertDatabaseCount('historial_estados_documento', 1);
        $this->assertDatabaseCount('auditoria_eventos', 1);
    }

    public function test_lima_cutoff_date_is_used_consistently_for_upload_and_review(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 02:00:00', 'UTC'));
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.fecha_actual', '2026-10-05');
        $this->cargar(cambios: ['fecha_emision' => '2026-10-06'])->assertUnprocessable();
        $id = $this->cargar()->assertCreated()->json('data.id');
        $this->assertSame('2026-10-05', DocumentoUsuario::query()->findOrFail($id)->fecha_incorporacion->toDateString());
        $this->actuarComo();
        $this->aceptar($id)->assertOk();
        $this->assertSame('2026-10-05', DocumentoUsuario::query()->findOrFail($id)->fecha_recepcion->toDateString());
    }
}
