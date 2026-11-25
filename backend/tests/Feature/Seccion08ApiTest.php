<?php

namespace Tests\Feature;

use App\Models\BitacoraAccesoLegajo;
use App\Models\DocumentoUsuario;
use App\Models\Legajo;
use App\Models\RecursoImpugnatorio;
use App\Models\Sancion;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Support\EmisionesPersonales;
use Database\Seeders\Seccion08CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\SubeDocumentos;
use Tests\TestCase;

class Seccion08ApiTest extends TestCase
{
    use RefreshDatabase;
    use SubeDocumentos;

    private Usuario $persona;

    private Legajo $legajo;

    private Usuario $autor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-06 12:00:00'));
        Storage::fake(config('legajo.disco_documentos'));
        $this->seed(Seccion08CatalogoSeeder::class);
        $this->persona = Usuario::factory()->create(['tipo_personal' => 'docente', 'regimen_laboral' => 'carrera']);
        $this->legajo = Legajo::factory()->create(['usuario_id' => $this->persona->id]);
        $this->autor = $this->actuarComo();
    }

    private function url(string $sufijo = ''): string
    {
        return '/api/v1/expedientes/'.$this->persona->id.'/seccion-08'.$sufijo;
    }

    /** @param array<string, mixed> $cambios */
    private function cargar(string $codigo, array $cambios = []): TestResponse
    {
        $tipo = TipoDocumento::query()->where('codigo', $codigo)->firstOrFail();
        $folio = (int) DocumentoUsuario::query()->where('legajo_id', $this->legajo->id)->where('seccion_legajo_id', 8)->max('folio_fin') + 1;
        $datos = ['tipo_documento_id' => $tipo->id, 'nombre' => 'Registro ficticio de prueba', 'emisor' => 'Entidad ficticia',
            'fecha_emision' => '2026-10-01', 'folio_inicio' => $folio, 'folio_fin' => $folio,
            'modalidad_presentacion' => 'copia_simple', 'pdf' => $this->pdf()];
        if (str_starts_with($codigo, 'REC_')) {
            $datos += ['tipo' => match ($codigo) {
                'REC_CARTA_FELICITACION' => 'carta', 'REC_LABOR_DOCENTE' => 'labor_docente', default => 'tiempo_servicios',
            }, 'motivo' => 'Mérito ficticio para prueba automatizada'];
        } elseif ($codigo === 'SAN_RESOLUCION_SANCION') {
            $datos += ['tipo' => 'SUSPENSION', 'num_resolucion' => 'RES-FICT-001', 'dias_suspension' => 3, 'registrada_en_rnssc' => 0];
        } elseif ($codigo === 'SAN_RESOLUCION_RECURSO') {
            $datos += ['num_resolucion' => 'RES-REC-FICT-001', 'sancion_id' => $cambios['sancion_id'] ?? '', 'resultado' => 'FUNDADO'];
        } else {
            $datos += ['num_resolucion' => 'RES-PAD-FICT-001'];
        }

        return $this->post($this->url('/documentos'), [...$datos, ...$cambios], ['Accept' => 'application/json']);
    }

    public function test_catalog_and_sanction_fields_are_masked_without_assigned_permission(): void
    {
        config(['permisos.roles.LECTOR' => ['legajo.ver']]);
        $this->actuarComo(null, 'LECTOR');
        $this->getJson($this->url())->assertOk()->assertJsonCount(3, 'data.grupos.reconocimientos')->assertJsonPath('data.grupos.sanciones', null)
            ->assertJsonPath('data.resumen.sanciones', null)->assertJsonPath('data.grupos.reconocimientos.0.nombre', 'Cartas, oficios, memorando de felicitación, condecoraciones, o diplomas otorgados en reconocimiento por excepcional participación en proyectos o eventos.')
            ->assertJsonPath('data.grupos.reconocimientos.1.estado', 'opcional');
        $this->getJson('/api/v1/expedientes/'.$this->persona->id.'/sanciones')->assertForbidden();
        $this->cargar('SAN_RESOLUCION_SANCION')->assertForbidden();
    }

    public function test_recognition_upload_is_accepted_directly_with_audit_and_folio(): void
    {
        $id = $this->cargar('REC_CARTA_FELICITACION')->assertCreated()->assertJsonPath('data.estado', 'aceptado')->json('data.id');
        $this->assertDatabaseHas('reconocimientos', ['documento_id' => $id, 'usuario_id' => $this->persona->id, 'tipo' => 'carta']);
        $this->assertDatabaseHas('historial_estados_documento', ['documento_usuario_id' => $id, 'estado_nuevo' => 'aceptado']);
        $this->assertDatabaseHas('auditoria_eventos', ['entidad_id' => $id, 'accion' => 'seccion08.documento.creado']);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.grupos.reconocimientos.0.documentos.0.id', $id)
            ->assertJsonMissingPath('data.numero_folios')->assertJsonMissingPath('data.linea_tiempo')->assertJsonPath('data.folio_sugerido', 2)->assertJsonPath('data.resumen.reconocimientos', 1);
    }

    public function test_time_service_recognition_reuses_existing_table_and_requires_a_complete_period(): void
    {
        $this->cargar('REC_TIEMPO_SERVICIOS', ['anios' => 12, 'meses' => null, 'dias' => 4])
            ->assertUnprocessable()->assertJsonValidationErrors('meses');
        $this->assertDatabaseCount('documentos_usuario', 0);

        $id = $this->cargar('REC_TIEMPO_SERVICIOS', ['anios' => 12, 'meses' => 3, 'dias' => 4, 'num_resolucion' => null])
            ->assertCreated()->json('data.id');
        $this->assertDatabaseHas('tiempo_servicios_reconocidos', [
            'documento_id' => $id, 'usuario_id' => $this->persona->id, 'numero_resolucion' => null,
            'anios' => 12, 'meses' => 3, 'dias' => 4,
        ]);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.grupos.reconocimientos.2.documentos.0.tiempo_servicio_reconocido.anios', 12);
    }

    public function test_teacher_recognition_is_not_applicable_to_non_teaching_personnel(): void
    {
        $this->persona->update(['tipo_personal' => 'administrativo']);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.grupos.reconocimientos.1.estado', 'no_aplica');
        $this->cargar('REC_LABOR_DOCENTE')->assertUnprocessable();
        $this->assertDatabaseCount('documentos_usuario', 0);
    }

    public function test_sanction_and_appeal_require_permission_and_preserve_linked_documents(): void
    {
        config(['permisos.pendientes' => ['seccion07.ver_calificacion']]);
        $sancionDocId = $this->cargar('SAN_RESOLUCION_SANCION')->assertCreated()->json('data.id');
        $sancion = Sancion::query()->where('documento_id', $sancionDocId)->firstOrFail();
        $this->assertSame('SUSPENSION', $sancion->tipo);
        $this->assertSame(3, $sancion->dias_suspension);

        $recursoDocId = $this->cargar('SAN_RESOLUCION_RECURSO', ['sancion_id' => $sancion->id])->assertCreated()->json('data.id');
        $this->assertDatabaseHas('recursos_impugnatorios', ['sancion_id' => $sancion->id, 'documento_id' => $recursoDocId, 'resultado' => 'FUNDADO']);
        $this->assertInstanceOf(RecursoImpugnatorio::class, RecursoImpugnatorio::query()->where('documento_id', $recursoDocId)->first());
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.resumen.dejadas_sin_efecto', 1);
        $this->cargar('SAN_RESOLUCION_RECURSO', ['sancion_id' => $sancion->id, 'resultado' => 'INFUNDADO'])->assertCreated();
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.grupos.sanciones.0.documentos.0.estado_sancion', null)
            ->assertJsonPath('data.resumen.dejadas_sin_efecto', 0)->assertJsonPath('data.resumen.vigentes', 0);
    }

    public function test_days_are_required_only_for_suspension_and_another_person_must_review(): void
    {
        config(['permisos.pendientes' => ['seccion07.ver_calificacion']]);
        $this->cargar('SAN_RESOLUCION_SANCION', ['tipo' => 'SUSPENSION', 'dias_suspension' => null])->assertUnprocessable()->assertJsonValidationErrors('dias_suspension');
        $this->cargar('SAN_RESOLUCION_SANCION', ['tipo' => 'DESTITUCION', 'dias_suspension' => 3])->assertUnprocessable()->assertJsonValidationErrors('dias_suspension');
        $id = $this->cargar('SAN_RESOLUCION_SANCION')->assertCreated()->json('data.id');
        $this->documentoAnteriorPendiente($id);
        $this->postJson($this->url('/documentos/'.$id.'/estado'), ['estado' => 'aceptado', 'estado_anterior' => 'pendiente', 'recepcion_confirmada' => true])->assertForbidden();
        $revisor = $this->actuarComo();
        $this->assertNotSame($this->autor->id, $revisor->id);
        $this->postJson($this->url('/documentos/'.$id.'/estado'), ['estado' => 'aceptado', 'estado_anterior' => 'pendiente', 'recepcion_confirmada' => true])->assertOk();
        $this->assertDatabaseHas('historial_estados_documento', ['documento_usuario_id' => $id, 'estado_nuevo' => 'aceptado']);
    }

    public function test_separation_sanctions_take_days_period_and_notification_and_others_reject_them(): void
    {
        $this->cargar('SAN_RESOLUCION_SANCION', ['tipo' => 'CESE_TEMPORAL', 'dias_suspension' => null])->assertUnprocessable()->assertJsonValidationErrors('dias_suspension');
        $this->cargar('SAN_RESOLUCION_SANCION', ['tipo' => 'AMONESTACION_VERBAL', 'dias_suspension' => null, 'fecha_inicio' => '2026-10-02', 'fecha_fin' => '2026-10-05'])
            ->assertUnprocessable()->assertJsonValidationErrors(['fecha_inicio', 'fecha_fin']);
        $this->cargar('SAN_RESOLUCION_SANCION', ['fecha_inicio' => '2026-10-05', 'fecha_fin' => '2026-10-02'])->assertUnprocessable()->assertJsonValidationErrors('fecha_fin');
        $this->cargar('SAN_RESOLUCION_SANCION', ['fecha_inicio' => '2026-10-02'])->assertUnprocessable()->assertJsonValidationErrors('fecha_fin');
        // La notificación no puede ser anterior al documento ni futura.
        $this->cargar('SAN_RESOLUCION_SANCION', ['fecha_notificacion' => '2026-09-30'])->assertUnprocessable()->assertJsonValidationErrors('fecha_notificacion');
        $this->cargar('SAN_RESOLUCION_SANCION', ['fecha_notificacion' => '2026-10-07'])->assertUnprocessable()->assertJsonValidationErrors('fecha_notificacion');
        $this->cargar('SAN_RESOLUCION_SANCION', ['tipo' => 'NO_EXISTE'])->assertUnprocessable()->assertJsonValidationErrors('tipo');
        $this->assertDatabaseCount('sanciones', 0);

        $id = $this->cargar('SAN_RESOLUCION_SANCION', ['tipo' => 'CESE_TEMPORAL', 'dias_suspension' => 30, 'fecha_inicio' => '2026-10-02', 'fecha_fin' => '2026-10-31', 'fecha_notificacion' => '2026-10-02'])
            ->assertCreated()->json('data.id');
        $sancion = Sancion::query()->where('documento_id', $id)->firstOrFail();
        $this->assertSame(['CESE_TEMPORAL', '2026-10-02', '2026-10-31', '2026-10-02'], [$sancion->tipo, $sancion->fecha_inicio->toDateString(), $sancion->fecha_fin->toDateString(), $sancion->fecha_notificacion->toDateString()]);
        $this->cargar('SAN_RESOLUCION_SANCION', ['tipo' => 'AMONESTACION_VERBAL', 'dias_suspension' => null])->assertCreated();
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.grupos.sanciones.0.documentos.0.fecha_notificacion', '2026-10-02')
            ->assertJsonPath('data.grupos.sanciones.0.documentos.0.fecha_fin_sancion', '2026-10-31');
    }

    public function test_rehabilitation_is_recorded_once_keeps_the_sanction_and_hides_it_from_the_report(): void
    {
        $id = $this->cargar('SAN_RESOLUCION_SANCION')->assertCreated()->json('data.id');
        $sancion = Sancion::query()->where('documento_id', $id)->firstOrFail();
        $url = $this->url('/sanciones/'.$sancion->id.'/rehabilitar');
        $this->postJson($url, ['fecha' => '2026-09-30', 'numero_resolucion' => 'RES-REHAB-01'])->assertUnprocessable()->assertJsonValidationErrors('fecha');
        $this->postJson($url, ['fecha' => '2026-10-07', 'numero_resolucion' => 'RES-REHAB-01'])->assertUnprocessable()->assertJsonValidationErrors('fecha');
        $this->postJson($url, ['fecha' => '2026-10-05', 'numero_resolucion' => '   '])->assertUnprocessable()->assertJsonValidationErrors('numero_resolucion');
        // Una sanción de otra persona no se encuentra por esta ruta.
        $otra = Usuario::factory()->create();
        $this->postJson('/api/v1/expedientes/'.$otra->id.'/seccion-08/sanciones/'.$sancion->id.'/rehabilitar', ['fecha' => '2026-10-05', 'numero_resolucion' => 'X'])->assertNotFound();

        $this->postJson($url, ['fecha' => '2026-10-05', 'numero_resolucion' => 'RES-REHAB-01'])->assertOk()->assertJsonPath('data.rehabilitada_el', '2026-10-05');
        $this->postJson($url, ['fecha' => '2026-10-05', 'numero_resolucion' => 'RES-REHAB-02'])->assertConflict();
        $this->assertDatabaseHas('sanciones', ['id' => $sancion->id, 'resolucion_rehabilitacion' => 'RES-REHAB-01', 'rehabilitada_por' => $this->autor->id, 'anulado' => false]);
        $this->assertDatabaseHas('auditoria_eventos', ['entidad_id' => $sancion->id, 'accion' => 'seccion08.sancion.rehabilitada']);
        $this->assertDatabaseHas('documentos_usuario', ['id' => $id, 'estado' => 'aceptado']);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.grupos.sanciones.0.documentos.0.rehabilitada_el', '2026-10-05')->assertJsonPath('data.resumen.rehabilitadas', 1);

        $informe = EmisionesPersonales::emitir($this->autor, $this->persona, $this->legajo, 'informe-escalafonario');
        $this->assertSame([], $informe['sanciones']);
        $this->assertSame(1, $informe['sanciones_rehabilitadas']);

        config(['permisos.roles.SOLO_VER' => ['legajo.ver', 'seccion08.sanciones.ver']]);
        $this->actuarComo(null, 'SOLO_VER');
        $this->postJson($url, ['fecha' => '2026-10-05', 'numero_resolucion' => 'RES-REHAB-03'])->assertForbidden();
    }

    public function test_disciplinary_procedure_documents_are_sanction_documents_but_not_sanctions(): void
    {
        $id = $this->cargar('SAN_INICIO_PAD')->assertCreated()->json('data.id');
        $this->cargar('SAN_MEDIDA_CAUTELAR')->assertCreated();
        $this->assertDatabaseCount('sanciones', 0);
        $this->assertDatabaseHas('auditoria_eventos', ['entidad_id' => $id, 'accion' => 'seccion08.documento.creado']);
        $datos = $this->getJson($this->url())->assertOk()->assertJsonPath('data.resumen.sanciones', 0)->json('data.grupos.sanciones');
        $this->assertContains('SAN_INICIO_PAD', array_column($datos, 'codigo'));
        // Quien no ve sanciones no los ve ni puede cargarlos.
        config(['permisos.roles.LECTOR' => ['legajo.ver', 'documento.subir']]);
        $this->actuarComo(null, 'LECTOR');
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.grupos.sanciones', null);
        $this->cargar('SAN_MEDIDA_CAUTELAR')->assertForbidden();
    }

    public function test_opening_sanction_pdf_is_denied_without_permission_and_logged_when_authorized(): void
    {
        config(['permisos.pendientes' => ['seccion07.ver_calificacion']]);
        config(['permisos.roles.ADMIN' => ['legajo.ver', 'seccion08.sanciones.registrar']]);
        $id = $this->cargar('SAN_RESOLUCION_SANCION')->assertCreated()->json('data.id');
        config(['permisos.roles.LECTOR' => ['legajo.ver']]);
        $this->actuarComo(null, 'LECTOR');
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.grupos.sanciones', null);
        $this->getJson('/api/v1/expedientes/'.$this->persona->id.'/documentos/'.$id.'/archivo')->assertForbidden();
        $this->assertDatabaseCount('bitacora_accesos_legajo', 0);
        config(['permisos.roles.ADMIN' => ['legajo.ver', 'seccion08.sanciones.ver']]);
        $this->actingAs($this->autor->fresh(), 'sanctum');
        $this->get('/api/v1/expedientes/'.$this->persona->id.'/documentos/'.$id.'/archivo')->assertOk();
        $this->assertDatabaseHas('bitacora_accesos_legajo', ['documento_id' => $id, 'usuario_id' => $this->autor->id, 'accion' => 'ver']);
        $this->assertDatabaseHas('auditoria_eventos', ['entidad_id' => $id, 'accion' => 'seccion08.documento.ver']);
        $this->assertInstanceOf(BitacoraAccesoLegajo::class, BitacoraAccesoLegajo::query()->where('documento_id', $id)->first());
    }

    public function test_admin_can_annul_a_section08_document_with_a_reason_and_keeps_its_history(): void
    {
        $id = $this->cargar('REC_CARTA_FELICITACION')->assertCreated()->json('data.id');
        $this->documentoAnteriorPendiente($id);
        $revisor = $this->actuarComo(null, 'ADMIN');
        $this->postJson($this->url('/documentos/'.$id.'/estado'), [
            'estado' => 'anulado', 'estado_anterior' => 'pendiente', 'descripcion' => 'Duplicado ficticio para prueba',
        ])->assertOk()->assertJsonPath('data.estado', 'anulado');
        $this->assertDatabaseHas('documentos_usuario', ['id' => $id, 'estado' => 'anulado']);
        $this->assertDatabaseHas('historial_estados_documento', [
            'documento_usuario_id' => $id, 'estado_nuevo' => 'anulado', 'cambiado_por' => $revisor->id,
            'motivo' => 'Duplicado ficticio para prueba',
        ]);
    }
}
