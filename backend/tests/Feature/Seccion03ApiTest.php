<?php

namespace Tests\Feature;

use App\Models\AuditoriaEvento;
use App\Models\DocumentoUsuario;
use App\Models\Legajo;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use App\Support\ResumenSeccion03;
use Database\Seeders\Seccion03CatalogoSeeder;
use Database\Seeders\SeccionesLegajoCatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SubeDocumentos;
use Tests\TestCase;

class Seccion03ApiTest extends TestCase
{
    use RefreshDatabase;
    use SubeDocumentos;

    private Usuario $persona;

    private Legajo $legajo;

    private Usuario $autor;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('legajo.disco_documentos'));
        $this->seed([SeccionesLegajoCatalogoSeeder::class, Seccion03CatalogoSeeder::class]);
        // Sin vínculo vigente, las condiciones del puesto salen de la persona: en No, a y c no aplican.
        $this->persona = Usuario::factory()->create(['tipo_personal' => 'docente', 'exige_estudios_basicos' => false, 'exige_colegiatura' => false]);
        $this->legajo = Legajo::factory()->create(['usuario_id' => $this->persona->id]);
        $this->autor = $this->actuarComo();
    }

    private function url(string $sufijo = ''): string
    {
        return '/api/v1/expedientes/'.$this->persona->id.'/seccion-03'.$sufijo;
    }

    private function cargar(string $codigo = 'FORM_TITULO_GRADO_SUPERIOR', array $cambios = [])
    {
        $tipo = TipoDocumento::query()->where('codigo', $codigo)->firstOrFail();
        $siguiente = (int) DocumentoUsuario::query()->where('legajo_id', $this->legajo->id)->max('folio_fin') + 1;
        $datos = [
            'tipo_documento_id' => $tipo->id, 'nombre' => 'Título profesional de ejemplo', 'emisor' => 'Universidad de ejemplo',
            'fecha_emision' => today()->subDay()->toDateString(), 'folio_inicio' => $siguiente, 'folio_fin' => $siguiente,
            'modalidad_presentacion' => 'copia_fedateada', 'pdf' => $this->pdf(),
        ];
        if (in_array($codigo, ['FORM_TITULO_GRADO_SUPERIOR', 'FORM_MAESTRIA_DOCTORADO'], true)) {
            $datos['nivel'] = $codigo === 'FORM_MAESTRIA_DOCTORADO' ? 'maestria' : 'titulo_profesional';
        }
        if ($codigo === 'FORM_COLEGIATURA') {
            $datos['numero_colegiatura'] = 'CIP-001';
        }
        if (in_array($codigo, ['FORM_CAPACITACION', 'FORM_CONGRESOS'], true)) {
            $datos['tipo_capacitacion'] = $codigo === 'FORM_CAPACITACION' ? 'curso' : 'congreso';
        }

        return $this->post($this->url('/documentos'), [...$datos, ...$cambios], ['Accept' => 'application/json']);
    }

    private function revisar(string $documento, array $datos = [])
    {
        return $this->postJson($this->url('/documentos/'.$documento.'/estado'), $datos + [
            'estado' => 'aceptado', 'estado_anterior' => 'pendiente', 'recepcion_confirmada' => true, 'fedateado' => true,
        ]);
    }

    public function test_catalog_is_repeatable_and_does_not_overwrite_existing_types(): void
    {
        $tipo = TipoDocumento::query()->where('codigo', 'FORM_OTROS')->firstOrFail();
        $tipo->update(['nombre' => 'Nombre institucional']);
        $this->seed(Seccion03CatalogoSeeder::class);
        $this->assertSame(8, TipoDocumento::query()->where('seccion_legajo_id', 3)->count());
        $this->assertSame('Nombre institucional', $tipo->fresh()->nombre);
        $this->assertSame(Seccion03CatalogoSeeder::id(8), $tipo->id);
    }

    public function test_requirements_follow_person_flags_and_teacher_type(): void
    {
        $respuesta = $this->getJson($this->url())->assertOk();
        $items = collect($respuesta->json('data.items'))->keyBy('letra');
        $this->assertSame(['no_aplica', 'falta', 'no_aplica', 'opcional', 'falta', 'opcional', 'opcional', 'opcional'], $items->pluck('estado')->all());
        $respuesta->assertJsonPath('data.resumen.exigidos', 2);
        $this->persona->update(['exige_estudios_basicos' => true, 'exige_colegiatura' => true]);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.resumen.exigidos', 4);
        $this->persona->update(['tipo_personal' => 'administrativo']);
        $respuesta = $this->getJson($this->url())->assertOk()->assertJsonPath('data.resumen.exigidos', 3);
        $this->assertSame('opcional', collect($respuesta->json('data.items'))->firstWhere('letra', 'e')['estado']);
    }

    public function test_requirements_include_active_employment_flags_and_ignore_annulled_links(): void
    {
        $vinculo = VinculoLaboral::factory()->create([
            'usuario_id' => $this->persona->id, 'tipo_personal' => 'docente',
            'exige_estudios_basicos' => true, 'exige_colegiatura' => true, 'fecha_cese' => null,
            'nombre_puesto' => 'Docente de ingeniería',
        ]);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.resumen.exigidos', 4)
            ->assertJsonPath('data.perfil.puestos.0', 'Docente de ingeniería');
        $vinculo->update(['anulado' => true]);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.resumen.exigidos', 2);
    }

    public function test_upload_accepts_evidence_directly_with_academic_data_history_hash_and_audit(): void
    {
        $id = $this->cargar(cambios: ['numero_registro' => 'REG-001'])->assertCreated()->assertJsonPath('data.estado', 'aceptado')->json('data.id');
        $doc = DocumentoUsuario::query()->with('archivo')->findOrFail($id);
        $this->assertDatabaseHas('formacion_academica', ['documento_id' => $id, 'numero_registro_sunedu' => 'REG-001', 'registrado_por' => $this->autor->id]);
        $this->assertDatabaseHas('historial_estados_documento', ['documento_usuario_id' => $id, 'estado_nuevo' => 'aceptado', 'cambiado_por' => $this->autor->id]);
        $this->assertDatabaseHas('auditoria_eventos', ['entidad_id' => $id, 'accion' => 'formacion.documento.creado', 'actor_usuario_id' => $this->autor->id]);
        $this->assertSame('pendiente', $doc->archivo->estado_antivirus);
        $this->assertSame(hash('sha256', Storage::disk(config('legajo.disco_documentos'))->get($doc->archivo->storage_path)), $doc->archivo->hash_sha256);
        $this->assertDatabaseHas('legajo_secciones', ['legajo_id' => $this->legajo->id, 'seccion_legajo_id' => 3, 'ultimo_folio' => 1]);
        $this->assertSame($this->persona->numero_doc_identidad, $doc->titular_documento);
    }

    public function test_direct_acceptance_does_not_certify_external_verification_and_closes_corrected_observations(): void
    {
        $id = $this->cargar()->assertCreated()->assertJsonPath('data.estado', 'aceptado')->json('data.id');
        $doc = DocumentoUsuario::query()->findOrFail($id);
        $this->assertFalse($doc->fiscalizado);
        $this->assertFalse($doc->fedateado);
        $this->assertNull($doc->fecha_recepcion);
        $this->assertDatabaseHas('historial_estados_documento', [
            'documento_usuario_id' => $id, 'estado_anterior' => null, 'estado_nuevo' => 'aceptado', 'cambiado_por' => $this->autor->id,
        ]);
        $this->actuarComo();
        $this->revisar($id, ['estado' => 'observado', 'estado_anterior' => 'aceptado', 'descripcion' => 'Copia ilegible',
            'plazo_subsanacion' => today()->addDays(10)->toDateString()])->assertOk();
        $this->actuarComo($this->autor);
        $nuevo = $this->cargar(cambios: ['subsana_a_documento_id' => $id])->assertCreated()
            ->assertJsonPath('data.estado', 'aceptado')->json('data.id');
        $this->assertDatabaseHas('observaciones_documento', [
            'documento_id' => $id, 'estado' => 'subsanada', 'documento_subsanacion_id' => $nuevo, 'verificada_por' => $this->autor->id,
        ]);
        $this->assertDatabaseHas('documentos_usuario', ['id' => $id, 'estado' => 'observado']);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.resumen.aceptados', 1)->assertJsonPath('data.resumen.pendientes', 0);
    }

    public function test_correlative_folios_reject_gaps_overlaps_and_reversed_ranges_without_orphans(): void
    {
        $this->cargar()->assertCreated();
        foreach ([[1, 1], [3, 3], [2, 1]] as [$inicio, $fin]) {
            $this->cargar('FORM_POSTGRADO', ['folio_inicio' => $inicio, 'folio_fin' => $fin])->assertUnprocessable();
        }
        $this->assertDatabaseCount('documentos_usuario', 1);
        $this->assertDatabaseCount('archivos_documento', 1);
        $this->assertCount(1, Storage::disk(config('legajo.disco_documentos'))->allFiles());
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.folio_sugerido', 2);
    }

    public function test_pdf_limits_type_and_unknown_fields_are_validated_on_server(): void
    {
        $this->cargar(cambios: ['pdf' => UploadedFile::fake()->createWithContent('falso.pdf', 'esto no es PDF')])->assertUnprocessable()->assertJsonValidationErrors('pdf');
        config(['legajo.tamano_maximo_pdf_kb' => 1]);
        $this->cargar(cambios: ['pdf' => $this->pdf(relleno: 2000)])->assertUnprocessable()->assertJsonValidationErrors('pdf');
        $this->cargar(cambios: ['created_by' => $this->persona->id])->assertUnprocessable()->assertJsonValidationErrors('created_by');
        $this->cargar(cambios: ['nivel' => 'doctorado'])->assertUnprocessable()->assertJsonValidationErrors('nivel');
        $this->assertDatabaseCount('documentos_usuario', 0);
    }

    public function test_non_applicable_items_and_missing_legajo_are_rejected(): void
    {
        $this->cargar('FORM_ESTUDIOS_BASICOS')->assertUnprocessable();
        $this->cargar('FORM_COLEGIATURA')->assertUnprocessable();
        $this->legajo->update(['estado_legajo' => 'pasivo']);
        $this->cargar()->assertConflict();
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.legajo.id', $this->legajo->id)->assertJsonPath('data.legajo.estado_legajo', 'pasivo');
    }

    public function test_structured_data_is_linked_for_college_postgraduate_and_training(): void
    {
        $this->persona->update(['exige_colegiatura' => true, 'exige_estudios_basicos' => true]);
        foreach (['FORM_ESTUDIOS_BASICOS', 'FORM_COLEGIATURA', 'FORM_POSTGRADO', 'FORM_MAESTRIA_DOCTORADO', 'FORM_CAPACITACION', 'FORM_CONGRESOS', 'FORM_OTROS'] as $codigo) {
            $this->cargar($codigo)->assertCreated();
        }
        $this->assertDatabaseCount('formacion_academica', 3);
        $this->assertDatabaseCount('colegiaturas', 1);
        $this->assertDatabaseCount('capacitaciones', 3);
        $this->assertDatabaseMissing('capacitaciones', ['documento_id' => null]);
    }

    public function test_author_and_assistant_cannot_review_but_another_admin_can(): void
    {
        $id = $this->cargar()->assertCreated()->json('data.id');
        $this->documentoAnteriorPendiente($id);
        $this->revisar($id)->assertForbidden();
        $this->actuarComo(codigo: 'asistente_legajo');
        $this->revisar($id)->assertForbidden();
        $revisor = $this->actuarComo();
        $this->revisar($id)->assertOk()->assertJsonPath('data.estado', 'aceptado')->assertJsonPath('data.fiscalizado', false);
        $this->assertDatabaseHas('documentos_usuario', ['id' => $id, 'recepcionado_por' => $revisor->id, 'fedateado' => true]);
        $this->assertDatabaseHas('historial_estados_documento', ['documento_usuario_id' => $id, 'estado_anterior' => 'pendiente', 'estado_nuevo' => 'aceptado']);
        $eventos = AuditoriaEvento::query()->where('entidad_id', $id)->orderBy('id')->get();
        $this->assertCount(2, $eventos);
        $this->assertSame($eventos[0]->hash_registro, $eventos[1]->hash_previo);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.resumen.aceptados', 1)->assertJsonPath('data.resumen.porcentaje', 50);
    }

    public function test_acceptance_requires_receipt_fedateo_and_rejects_infected_file(): void
    {
        $id = $this->cargar()->assertCreated()->json('data.id');
        $this->documentoAnteriorPendiente($id);
        $this->actuarComo();
        $this->revisar($id, ['recepcion_confirmada' => false])->assertUnprocessable();
        $this->revisar($id, ['fedateado' => false])->assertUnprocessable();
        DocumentoUsuario::query()->findOrFail($id)->archivo->update(['estado_antivirus' => 'infectado']);
        $this->revisar($id)->assertUnprocessable();
        $this->getJson('/api/v1/expedientes/'.$this->persona->id.'/documentos/'.$id.'/archivo')->assertStatus(423);
        $this->assertDatabaseCount('historial_estados_documento', 1);
    }

    public function test_observation_subsanation_and_acceptance_preserve_original_evidence(): void
    {
        $id = $this->cargar()->assertCreated()->json('data.id');
        $this->documentoAnteriorPendiente($id);
        $this->actuarComo();
        $this->revisar($id, ['estado' => 'observado', 'descripcion' => 'Copia ilegible', 'plazo_subsanacion' => today()->addDays(10)->toDateString()])->assertOk();
        $this->assertDatabaseHas('observaciones_documento', ['documento_id' => $id, 'estado' => 'abierta', 'descripcion' => 'Copia ilegible']);
        $this->revisar($id, ['estado_anterior' => 'observado'])->assertConflict();
        $this->actuarComo($this->autor);
        $nuevo = $this->cargar(cambios: ['subsana_a_documento_id' => $id])->assertCreated()->json('data.id');
        $this->documentoAnteriorPendiente($nuevo);
        $items = collect($this->getJson($this->url())->assertOk()->json('data.items'))->keyBy('letra');
        $this->assertSame('pendiente', $items['b']['estado']);
        $this->assertCount(2, $items['b']['documentos']);
        $this->actuarComo();
        $this->revisar($nuevo)->assertOk();
        $this->assertDatabaseHas('documentos_usuario', ['id' => $id, 'estado' => 'observado']);
        $this->assertDatabaseHas('observaciones_documento', ['documento_id' => $id, 'estado' => 'subsanada', 'documento_subsanacion_id' => $nuevo]);
        $this->revisar($id, ['estado_anterior' => 'observado'])->assertConflict();
    }

    public function test_single_document_requires_subsanation_and_cannot_subsane_another_type(): void
    {
        $id = $this->cargar()->assertCreated()->json('data.id');
        $this->cargar()->assertUnprocessable()->assertJsonValidationErrors('subsana_a_documento_id');
        $this->cargar('FORM_POSTGRADO', ['subsana_a_documento_id' => $id])->assertUnprocessable();
        $this->assertDatabaseCount('documentos_usuario', 1);
    }

    public function test_stale_review_is_rejected_without_duplicate_history(): void
    {
        $id = $this->cargar()->assertCreated()->json('data.id');
        $this->documentoAnteriorPendiente($id);
        $this->actuarComo();
        $this->revisar($id)->assertOk();
        $this->revisar($id, ['estado' => 'observado', 'descripcion' => 'Revisar', 'plazo_subsanacion' => today()->toDateString()])->assertConflict();
        $this->assertDatabaseCount('historial_estados_documento', 2);
        $this->assertDatabaseCount('observaciones_documento', 0);
    }

    public function test_sensitive_types_are_hidden_and_their_upload_is_forbidden(): void
    {
        $tipo = TipoDocumento::query()->where('codigo', 'FORM_TITULO_GRADO_SUPERIOR')->firstOrFail();
        $this->cargar()->assertCreated();
        $tipo->update(['es_dato_sensible' => true]);
        $this->actuarComo(codigo: 'asistente_legajo');
        $this->getJson($this->url())->assertOk()->assertJsonCount(7, 'data.items');
        $this->getJson('/api/v1/expedientes/'.$this->persona->id.'/documentos-usuario?seccion_id=3')->assertOk()->assertJsonCount(0, 'data');
        $this->cargar()->assertForbidden();
        $this->getJson('/api/v1/expedientes/'.$this->persona->id.'/formacion-academica')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/expedientes/'.$this->persona->id.'/archivos-documento')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_review_cannot_target_another_person_and_non_privileged_roles_cannot_upload(): void
    {
        $id = $this->cargar()->assertCreated()->json('data.id');
        $this->documentoAnteriorPendiente($id);
        $otra = Usuario::factory()->create();
        Legajo::factory()->create(['usuario_id' => $otra->id]);
        $this->actuarComo();
        $this->postJson('/api/v1/expedientes/'.$otra->id.'/seccion-03/documentos/'.$id.'/estado', [
            'estado' => 'aceptado', 'estado_anterior' => 'pendiente', 'recepcion_confirmada' => true,
        ])->assertNotFound();
        config(['permisos.roles.consulta' => ['legajo.ver']]);
        $this->actuarComo(codigo: 'consulta');
        $this->getJson($this->url())->assertOk();
        $this->cargar()->assertForbidden();
        $this->revisar($id)->assertForbidden();
    }

    public function test_catalog_migration_is_reversible_without_deleting_other_types(): void
    {
        $otro = TipoDocumento::factory()->create(['seccion_legajo_id' => 3, 'codigo' => 'TIPO_INSTITUCIONAL']);
        $migracion = require database_path('migrations/2026_10_05_025340_seed_seccion03_catalogo.php');
        $migracion->down();
        $this->assertDatabaseHas('tipos_documento', ['id' => $otro->id]);
        $this->assertSame(1, TipoDocumento::query()->where('seccion_legajo_id', 3)->count());
        $migracion->up();
        $this->assertSame(9, TipoDocumento::query()->where('seccion_legajo_id', 3)->count());
    }

    public function test_catalog_rollback_preserves_types_with_documentary_evidence(): void
    {
        $this->cargar()->assertCreated();
        $migracion = require database_path('migrations/2026_10_05_025340_seed_seccion03_catalogo.php');
        try {
            $migracion->down();
            $this->fail('No debe revertirse un catálogo con evidencia.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('tiene documentos', $exception->getMessage());
        }
        $this->assertSame(8, TipoDocumento::query()->where('seccion_legajo_id', 3)->count());
        $this->assertDatabaseCount('documentos_usuario', 1);
    }

    public function test_audit_failure_rolls_back_the_document_data_and_reserved_folios(): void
    {
        Event::listen('eloquent.creating: '.AuditoriaEvento::class, function (): void {
            throw new \RuntimeException('Auditoría no disponible');
        });
        $this->withoutExceptionHandling();
        try {
            $this->cargar();
            $this->fail('La carga no debe confirmarse sin auditoría.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Auditoría no disponible', $exception->getMessage());
        }
        $this->assertDatabaseCount('documentos_usuario', 0);
        $this->assertDatabaseCount('archivos_documento', 0);
        $this->assertDatabaseCount('formacion_academica', 0);
        $this->assertDatabaseCount('historial_estados_documento', 0);
        $this->assertDatabaseCount('legajo_secciones', 0);
        $this->assertCount(0, Storage::disk(config('legajo.disco_documentos'))->allFiles());
    }

    public function test_state_priority_excludes_annulled_and_subsaned_documents(): void
    {
        $documento = fn (string $estado, bool $subsanado = false): array => ['estado' => $estado, 'subsanado' => $subsanado];
        $this->assertSame('falta', ResumenSeccion03::estado(true, false, collect([$documento('anulado')])));
        $this->assertSame('aceptado', ResumenSeccion03::estado(true, false, collect([$documento('aceptado'), $documento('observado', true)])));
        $this->assertSame('observado', ResumenSeccion03::estado(true, false, collect([$documento('observado'), $documento('pendiente')])));
        $this->assertSame('pendiente', ResumenSeccion03::estado(true, false, collect([$documento('aceptado'), $documento('pendiente')])));
        $this->assertSame('aceptado', ResumenSeccion03::estado(true, false, collect([$documento('aceptado')])));
    }
}
