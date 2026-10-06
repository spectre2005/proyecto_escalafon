<?php

namespace Tests\Feature;

use App\Models\AuditoriaEvento;
use App\Models\DocumentoUsuario;
use App\Models\ExperienciaLaboral;
use App\Models\Legajo;
use App\Models\ParametroSistema;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use Database\Seeders\Seccion04CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\SubeDocumentos;
use Tests\TestCase;

class Seccion04ApiTest extends TestCase
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
        $this->seed(Seccion04CatalogoSeeder::class);
        $this->persona = Usuario::factory()->create(['tipo_personal' => 'docente', 'regimen_laboral' => 'carrera']);
        $this->legajo = Legajo::factory()->create(['usuario_id' => $this->persona->id]);
        $this->autor = $this->actuarComo();
    }

    private function url(string $sufijo = ''): string
    {
        return '/api/v1/expedientes/'.$this->persona->id.'/seccion-04'.$sufijo;
    }

    private function cargar(string $codigo = 'EXP_CERTIFICADO_TRABAJO', array $cambios = []): TestResponse
    {
        $tipo = TipoDocumento::query()->where('codigo', $codigo)->firstOrFail();
        $folio = (int) DocumentoUsuario::query()->where('legajo_id', $this->legajo->id)->where('seccion_legajo_id', 4)->max('folio_fin') + 1;
        $datos = ['tipo_documento_id' => $tipo->id, 'nombre' => 'Constancia de ejemplo', 'emisor' => 'Entidad de ejemplo', 'fecha_emision' => '2026-10-01',
            'folio_inicio' => $folio, 'folio_fin' => $folio, 'modalidad_presentacion' => 'copia_fedateada', 'pdf' => $this->pdf()];
        if ($codigo === 'EXP_CERTIFICADO_TRABAJO') {
            $datos += ['sector' => 'publico', 'cargo' => 'Docente', 'fecha_inicio' => '2024-01-01', 'fecha_fin' => '2024-01-10', 'tipo_vinculo' => 'planilla',
                'es_ejercicio_profesional' => true, 'es_docencia_universitaria' => true];
        }
        if ($codigo === 'EXP_RESOLUCION_FUNCIONES') {
            $datos += ['num_resolucion' => 'MEM-001', 'funcion_asignada' => 'Coordinar la escuela profesional'];
        }

        return $this->post($this->url('/documentos'), [...$datos, ...$cambios], ['Accept' => 'application/json']);
    }

    private function aceptar(string $id): TestResponse
    {
        return $this->postJson($this->url('/documentos/'.$id.'/estado'), ['estado' => 'aceptado', 'estado_anterior' => 'pendiente', 'recepcion_confirmada' => true, 'fedateado' => true]);
    }

    public function test_catalog_is_idempotent_and_requirement_flags_are_respected(): void
    {
        $this->seed(Seccion04CatalogoSeeder::class);
        $this->assertSame(3, TipoDocumento::query()->where('seccion_legajo_id', 4)->count());
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.resumen.exigidos', 1)->assertJsonPath('data.items.0.estado', 'falta')->assertJsonPath('data.items.1.estado', 'opcional');
        TipoDocumento::query()->where('codigo', 'EXP_CERTIFICADO_TRABAJO')->update(['requiere_administrativo' => true]);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.resumen.exigidos', 0)->assertJsonPath('data.items.0.estado', 'no_aplica');
        $this->persona->update(['tipo_personal' => 'administrativo']);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.resumen.exigidos', 1);
    }

    public function test_upload_is_pending_atomic_and_links_period_hash_history_and_audit(): void
    {
        $id = $this->cargar()->assertCreated()->assertJsonPath('data.estado', 'pendiente')->json('data.id');
        $doc = DocumentoUsuario::query()->with('archivo')->findOrFail($id);
        $this->assertDatabaseHas('experiencia_laboral', ['documento_id' => $id, 'registrado_por' => $this->autor->id, 'sector' => 'publico', 'tipo_vinculo' => 'planilla']);
        $this->assertDatabaseHas('historial_estados_documento', ['documento_usuario_id' => $id, 'estado_nuevo' => 'pendiente']);
        $this->assertDatabaseHas('auditoria_eventos', ['entidad_id' => $id, 'accion' => 'experiencia.documento.creado']);
        $this->assertSame('pendiente', $doc->archivo->estado_antivirus);
        $this->assertSame(hash('sha256', Storage::disk(config('legajo.disco_documentos'))->get($doc->archivo->storage_path)), $doc->archivo->hash_sha256);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.numero_folios', 1)->assertJsonPath('data.folio_sugerido', 2)->assertJsonPath('data.tiempo.total.dias_totales', 0);
        ParametroSistema::query()->where('clave', 'exp_incluir_pendientes')->update(['valor' => '1']);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.tiempo.total.dias_totales', 10);
    }

    public function test_multiple_certificates_count_each_required_document_and_prioritize_pending_state(): void
    {
        $id = $this->cargar()->assertCreated()->json('data.id');
        $this->actuarComo();
        $this->aceptar($id)->assertOk();
        $this->cargar(cambios: ['fecha_inicio' => '2024-02-01', 'fecha_fin' => '2024-02-10'])->assertCreated();
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.items.0.estado', 'pendiente')
            ->assertJsonPath('data.resumen.exigidos', 2)->assertJsonPath('data.resumen.aceptados', 1)->assertJsonPath('data.resumen.porcentaje', 50);
    }

    public function test_resolution_and_other_documents_do_not_create_artificial_periods(): void
    {
        $id = $this->cargar('EXP_RESOLUCION_FUNCIONES')->assertCreated()->json('data.id');
        $this->assertDatabaseHas('documentos_usuario', ['id' => $id, 'num_resolucion' => 'MEM-001', 'funcion_asignada' => 'Coordinar la escuela profesional']);
        $this->cargar('EXP_OTROS')->assertCreated();
        $this->assertDatabaseCount('experiencia_laboral', 0);
        $this->cargar('EXP_RESOLUCION_FUNCIONES', ['num_resolucion' => ''])->assertUnprocessable()->assertJsonValidationErrors('num_resolucion');
    }

    public function test_invalid_dates_sectors_pdf_and_unknown_fields_are_rejected_without_orphans(): void
    {
        foreach ([['fecha_fin' => '2023-12-31'], ['fecha_inicio' => '2027-01-01'], ['fecha_fin' => '2027-01-01'], ['sector' => 'otro'], ['tipo_vinculo' => 'desconocido'], ['created_by' => $this->persona->id], ['nombre' => '   ']] as $datos) {
            $this->cargar(cambios: $datos)->assertUnprocessable();
        }
        $this->cargar(cambios: ['pdf' => UploadedFile::fake()->createWithContent('falso.pdf', 'Texto')])->assertUnprocessable()->assertJsonValidationErrors('pdf');
        config(['legajo.tamano_maximo_pdf_kb' => 1]);
        $this->cargar(cambios: ['pdf' => $this->pdf(relleno: 2000)])->assertUnprocessable();
        $this->assertDatabaseCount('documentos_usuario', 0);
        $this->assertDatabaseCount('experiencia_laboral', 0);
        $this->assertCount(0, Storage::disk(config('legajo.disco_documentos'))->allFiles());
    }

    public function test_folios_are_correlative_per_section_and_bad_ranges_roll_back(): void
    {
        $this->cargar()->assertCreated();
        foreach ([[1, 1], [3, 3], [2, 1]] as [$inicio,$fin]) {
            $this->cargar(cambios: ['folio_inicio' => $inicio, 'folio_fin' => $fin])->assertUnprocessable();
        }
        $this->cargar('EXP_OTROS', ['folio_inicio' => 2, 'folio_fin' => 4])->assertCreated();
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.numero_folios', 4)->assertJsonPath('data.folio_sugerido', 5);
        $this->assertDatabaseCount('documentos_usuario', 2);
        $this->assertDatabaseCount('archivos_documento', 2);
    }

    public function test_review_cannot_be_own_and_requires_reception_fedateo_and_correct_owner(): void
    {
        $id = $this->cargar()->assertCreated()->json('data.id');
        $this->aceptar($id)->assertForbidden();
        $this->actuarComo();
        $this->postJson($this->url('/documentos/'.$id.'/estado'), ['estado' => 'aceptado', 'estado_anterior' => 'pendiente', 'recepcion_confirmada' => true, 'fedateado' => false])->assertUnprocessable()->assertJsonValidationErrors('fedateado');
        $otra = Usuario::factory()->create();
        $this->postJson('/api/v1/expedientes/'.$otra->id.'/seccion-04/documentos/'.$id.'/estado', ['estado' => 'aceptado', 'estado_anterior' => 'pendiente', 'recepcion_confirmada' => true])->assertConflict();
        $this->aceptar($id)->assertOk();
        $this->assertDatabaseHas('documentos_usuario', ['id' => $id, 'estado' => 'aceptado', 'fedateado' => true, 'fiscalizado' => false]);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.resumen.aceptados', 1)->assertJsonPath('data.tiempo.total.dias_totales', 10);
        $this->aceptar($id)->assertConflict();
    }

    public function test_observation_subsanation_and_annulment_keep_evidence_and_update_time(): void
    {
        $id = $this->cargar()->assertCreated()->json('data.id');
        $this->actuarComo();
        $this->postJson($this->url('/documentos/'.$id.'/estado'), ['estado' => 'observado', 'estado_anterior' => 'pendiente', 'descripcion' => 'Revisar fechas del servicio', 'plazo_subsanacion' => '2026-10-30'])->assertOk();
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.items.0.estado', 'observado');
        $nuevo = $this->cargar(cambios: ['subsana_a_documento_id' => $id, 'fecha_fin' => '2024-01-15'])->assertCreated()->json('data.id');
        $this->actuarComo();
        $this->aceptar($nuevo)->assertOk();
        $this->assertDatabaseHas('observaciones_documento', ['documento_id' => $id, 'estado' => 'subsanada', 'documento_subsanacion_id' => $nuevo]);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.tiempo.total.dias_totales', 15);
        $this->postJson($this->url('/documentos/'.$nuevo.'/estado'), ['estado' => 'anulado', 'estado_anterior' => 'aceptado', 'descripcion' => 'Documento duplicado'])->assertOk();
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.tiempo.total.dias_totales', 0)->assertJsonPath('data.numero_folios', 2);
        $this->assertDatabaseCount('documentos_usuario', 2);
        $this->assertCount(2, Storage::disk(config('legajo.disco_documentos'))->allFiles());
        $this->assertDatabaseHas('historial_estados_documento', ['documento_usuario_id' => $nuevo, 'estado_nuevo' => 'anulado']);
    }

    public function test_open_periods_blocked_files_and_unclassified_periods_are_handled(): void
    {
        $id = $this->cargar(cambios: ['fecha_fin' => null])->assertCreated()->json('data.id');
        $this->assertDatabaseHas('experiencia_laboral', ['documento_id' => $id, 'fecha_fin' => null]);
        $this->actuarComo();
        $periodo = ExperienciaLaboral::query()->where('documento_id', $id)->firstOrFail();
        $periodo->update(['es_ejercicio_profesional' => null]);
        $this->aceptar($id)->assertUnprocessable()->assertJsonValidationErrors('estado');
        $periodo->update(['es_ejercicio_profesional' => true]);
        $this->aceptar($id)->assertOk();
        $dias = (int) Carbon::parse('2024-01-01')->diffInDays(Carbon::parse('2026-10-05')) + 1;
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.tiempo.total.dias_totales', $dias);
        DocumentoUsuario::query()->findOrFail($id)->archivo->update(['estado_antivirus' => 'infectado']);
        $this->getJson('/api/v1/expedientes/'.$this->persona->id.'/documentos/'.$id.'/archivo')->assertStatus(423);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.tiempo.total.dias_totales', 0);
    }

    public function test_common_upload_and_generic_document_store_do_not_bypass_experience_data(): void
    {
        $tipo = TipoDocumento::query()->where('codigo', 'EXP_CERTIFICADO_TRABAJO')->firstOrFail();
        $this->post('/api/v1/expedientes/'.$this->persona->id.'/documentos', $this->metadatos($tipo, 1, 1), ['Accept' => 'application/json'])->assertUnprocessable();
        // El CRUD genérico de documentos-usuario no tiene store (sección 02): responde 405.
        $this->postJson('/api/v1/documentos-usuario', ['tipo_documento_id' => $tipo->id])->assertStatus(405);
        $this->assertDatabaseCount('documentos_usuario', 0);
    }

    public function test_assistant_can_upload_and_read_but_cannot_review(): void
    {
        $this->actuarComo(codigo: 'asistente_legajo');
        $id = $this->cargar()->assertCreated()->json('data.id');
        $this->getJson($this->url())->assertOk();
        $this->aceptar($id)->assertForbidden();
    }

    public function test_generic_crud_cannot_change_remove_or_relink_sustained_experience(): void
    {
        $id = $this->cargar()->assertCreated()->json('data.id');
        $periodo = ExperienciaLaboral::query()->where('documento_id', $id)->firstOrFail();
        $this->patchJson('/api/v1/experiencia-laboral/'.$periodo->id, ['cargo' => 'Cambio'])->assertConflict();
        $this->deleteJson('/api/v1/experiencia-laboral/'.$periodo->id)->assertConflict();
        $this->postJson('/api/v1/experiencia-laboral', ['documento_id' => $id])->assertConflict();
        $this->assertSame('Docente', $periodo->fresh()->cargo);
    }

    public function test_audit_failure_rolls_back_period_document_file_and_folios(): void
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
        $this->assertDatabaseCount('experiencia_laboral', 0);
        $this->assertDatabaseCount('legajo_secciones', 0);
        $this->assertDatabaseCount('historial_estados_documento', 0);
        $this->assertCount(0, Storage::disk(config('legajo.disco_documentos'))->allFiles());
    }

    public function test_catalog_rollback_preserves_existing_custom_types_and_blocks_referenced_rows(): void
    {
        TipoDocumento::query()->where('codigo', 'EXP_OTROS')->delete();
        $custom = TipoDocumento::factory()->create(['seccion_legajo_id' => 4, 'codigo' => 'EXP_OTROS']);
        $this->seed(Seccion04CatalogoSeeder::class);
        $migration = require database_path('migrations/2026_10_05_163133_seed_seccion04_catalogo.php');
        $migration->down();
        $this->assertDatabaseHas('tipos_documento', ['id' => $custom->id]);
        $migration->up();
        $this->cargar()->assertCreated();
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }
}
