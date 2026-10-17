<?php

namespace Tests\Feature;

use App\Models\ArchivoDocumento;
use App\Models\AvisoVencimiento;
use App\Models\CicloVigencia;
use App\Models\DocumentoUsuario;
use App\Models\EnvioVencimiento;
use App\Models\Legajo;
use App\Models\PerfilDocente;
use App\Models\PresentacionRenovacion;
use App\Models\SeccionLegajo;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use App\Support\AvisosVencimiento;
use App\Support\VigenciaLegajo;
use Carbon\Carbon;
use Database\Seeders\SeccionesLegajoCatalogoSeeder;
use Illuminate\Cache\ArrayStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class VencimientoLegajoTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $admin;

    private Usuario $cargador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-09 10:00:00', 'America/Lima'));
        $this->seed(SeccionesLegajoCatalogoSeeder::class);
        Storage::fake('documentos');
        config(['mail.default' => 'array']);
        $this->admin = $this->actuarComo();
        $this->cargador = Usuario::factory()->create();
    }

    public function test_calendar_years_leap_day_and_signed_boundary_states(): void
    {
        foreach (['auxiliar' => '2023-10-16', 'asociado' => '2021-10-16', 'principal' => '2019-10-16'] as $categoria => $base) {
            $resultado = VigenciaLegajo::calcular($categoria, $base);
            $this->assertSame('2026-10-16', $resultado['fecha_vencimiento']);
            $this->assertSame('2026-10-09', $resultado['fecha_aviso']);
            $this->assertSame(7, $resultado['dias_restantes']);
        }
        $this->assertSame('2027-02-28', VigenciaLegajo::calcular('auxiliar', '2024-02-29')['fecha_vencimiento']);
        foreach ([31 => 'verde', 30 => 'amarillo', 8 => 'amarillo', 7 => 'rojo', 1 => 'rojo', 0 => 'rojo', -1 => 'rojo'] as $dias => $color) {
            $base = VigenciaLegajo::hoy()->addDays($dias)->subYears(3)->toDateString();
            $resultado = VigenciaLegajo::calcular('auxiliar', $base);
            $this->assertSame($dias, $resultado['dias_restantes']);
            $this->assertSame($color, $resultado['color']);
            $this->assertSame($dias <= 0 ? 'vencido' : ($dias <= 30 ? 'por_vencer' : 'vigente'), $resultado['estado_vigencia']);
        }
    }

    public function test_initialization_uses_evidence_date_and_preserves_legajo(): void
    {
        [$legajo, $perfil, $base] = $this->docente();
        $this->postJson('/api/v1/vencimientos/legajos/'.$legajo->id.'/inicializar', ['perfil_docente_id' => $perfil->id, 'documento_id' => $base->id])->assertCreated();
        $ciclo = CicloVigencia::query()->sole();
        $this->assertSame('2023-10-16', $ciclo->fecha_base->toDateString());
        $this->assertSame('2026-10-16', $ciclo->fecha_vencimiento->toDateString());
        $this->assertDatabaseHas('legajos', ['id' => $legajo->id, 'estado_legajo' => 'activo']);
        $this->postJson('/api/v1/vencimientos/legajos/'.$legajo->id.'/inicializar', ['perfil_docente_id' => $perfil->id, 'documento_id' => $base->id])->assertConflict();
        $this->assertSame(1, CicloVigencia::query()->count());
    }

    public function test_missing_future_and_foreign_resolution_never_start_a_cycle(): void
    {
        [$legajo, $perfil, $base] = $this->docente();
        $base->update(['fecha_recepcion' => null]);
        $payload = ['perfil_docente_id' => $perfil->id, 'documento_id' => $base->id];
        $this->postJson('/api/v1/vencimientos/legajos/'.$legajo->id.'/inicializar', $payload)->assertUnprocessable();
        $base->update(['fecha_recepcion' => '2026-10-09', 'fecha_emision' => '2027-01-01']);
        $this->postJson('/api/v1/vencimientos/legajos/'.$legajo->id.'/inicializar', $payload)->assertUnprocessable();
        [$otro, , $ajeno] = $this->docente();
        $this->postJson('/api/v1/vencimientos/legajos/'.$legajo->id.'/inicializar', [...$payload, 'documento_id' => $ajeno->id])->assertUnprocessable();
        $this->assertSame(0, CicloVigencia::query()->count());
        $this->getJson('/api/v1/vencimientos')->assertOk()->assertJsonPath('resumen.revision', 2);
    }

    public function test_own_access_is_scoped_and_cannot_mutate_another_teacher(): void
    {
        [$propio] = $this->docente();
        [$ajeno, $perfil, $base] = $this->docente();
        $ciclo = VigenciaLegajo::inicializar($ajeno, $perfil, $base, $this->admin->id);
        $this->actuarComo($propio->usuario, 'personal');
        $this->getJson('/api/v1/vencimientos')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.legajo_id', $propio->id);
        $this->getJson('/api/v1/vencimientos/legajos/'.$ajeno->id)->assertForbidden();
        $this->postJson('/api/v1/vencimientos/ciclos/'.$ciclo->id.'/preparar')->assertForbidden();
        $this->postJson('/api/v1/vencimientos/ciclos/'.$ciclo->id.'/revisar', ['accion' => 'aprobar'])->assertForbidden();
        $this->getJson('/api/v1/vencimientos/opciones')->assertForbidden();
    }

    public function test_automatic_notification_occurs_at_seven_days_and_is_idempotent(): void
    {
        [$legajo, $perfil, $base] = $this->docente('auxiliar', '2023-10-17');
        $ciclo = VigenciaLegajo::inicializar($legajo, $perfil, $base, $this->admin->id);
        $this->artisan('legajos:controlar-vencimientos')->assertSuccessful();
        $this->assertSame(0, AvisoVencimiento::query()->count());
        $this->travelTo(Carbon::parse('2026-10-10 10:00:00', 'America/Lima'));
        $this->artisan('legajos:controlar-vencimientos')->assertSuccessful();
        $this->artisan('legajos:controlar-vencimientos')->assertSuccessful();
        $this->assertDatabaseHas('avisos_vencimiento', ['ciclo_id' => $ciclo->id, 'origen' => 'automatico']);
        $this->assertSame('2026-10-10', AvisoVencimiento::query()->sole()->fecha_programada->toDateString());
        $this->assertSame(1, AvisoVencimiento::query()->count());
        $this->assertSame(1, EnvioVencimiento::query()->count());
        $this->assertDatabaseHas('notificaciones_usuario', ['usuario_id' => $this->admin->id, 'tipo' => 'vencimiento_legajo']);
    }

    public function test_late_recovery_and_expiration_do_not_close_the_legajo(): void
    {
        [$legajo, $perfil, $base] = $this->docente('auxiliar', '2023-10-09');
        $ciclo = VigenciaLegajo::inicializar($legajo, $perfil, $base, $this->admin->id);
        $ciclo->update(['estado_vigencia' => 'vigente']);
        $this->artisan('legajos:controlar-vencimientos')->assertSuccessful();
        $this->assertDatabaseHas('avisos_vencimiento', ['ciclo_id' => $ciclo->id, 'origen' => 'recuperacion_tardia']);
        $this->assertSame('2026-10-02', AvisoVencimiento::query()->sole()->fecha_programada->toDateString());
        $this->assertDatabaseHas('ciclos_vigencia_legajo', ['id' => $ciclo->id, 'estado_vigencia' => 'vencido']);
        $this->assertDatabaseHas('legajos', ['id' => $legajo->id, 'estado_legajo' => 'activo']);
    }

    public function test_constancia_checks_live_expiration_without_scheduler_and_renewal_remains_available(): void
    {
        [$legajo, $perfil, $base] = $this->docente('auxiliar', '2023-10-09');
        $ciclo = VigenciaLegajo::inicializar($legajo, $perfil, $base, $this->admin->id);
        $ciclo->update(['estado_vigencia' => 'vigente']);
        $this->getJson('/api/v1/vencimientos/legajos/'.$legajo->id.'/constancia')->assertStatus(423);
        $this->getJson('/api/v1/vencimientos/legajos/'.$legajo->id)->assertOk()->assertJsonPath('data.estado_vigencia', 'vencido');
        $this->politica($this->tipo());
        $this->postJson('/api/v1/vencimientos/ciclos/'.$ciclo->id.'/preparar')->assertOk();
    }

    public function test_filters_summary_and_invalid_dates(): void
    {
        foreach (['2023-10-09', '2023-10-16', '2023-11-15'] as $fecha) {
            [$legajo, $perfil, $base] = $this->docente('auxiliar', $fecha);
            VigenciaLegajo::inicializar($legajo, $perfil, $base, $this->admin->id);
        }
        $this->getJson('/api/v1/vencimientos')->assertOk()->assertJsonPath('resumen.vencidos', 1)->assertJsonPath('resumen.por_vencer', 1);
        $this->getJson('/api/v1/vencimientos?categoria=auxiliar&estado=por_vencer&desde=2026-10-10&hasta=2026-10-30')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/v1/vencimientos?hasta=2026-10-30')->assertOk();
        $this->getJson('/api/v1/vencimientos?desde=2026-10-30&hasta=2026-10-01')->assertUnprocessable();
    }

    public function test_policy_requires_explicit_configuration_and_versions_are_preserved(): void
    {
        [$legajo, $perfil, $base] = $this->docente();
        $ciclo = VigenciaLegajo::inicializar($legajo, $perfil, $base, $this->admin->id);
        $this->postJson('/api/v1/vencimientos/ciclos/'.$ciclo->id.'/preparar')->assertUnprocessable();
        $tipo = $this->tipo();
        $payload = ['categoria' => 'auxiliar', 'tipo_documento_id' => $tipo->id, 'modo' => 'por_ciclo'];
        $this->postJson('/api/v1/vencimientos/politicas', $payload)->assertOk();
        $this->postJson('/api/v1/vencimientos/ciclos/'.$ciclo->id.'/preparar')->assertOk();
        $reglaOriginal = $ciclo->requerimientos()->sole()->politica_id;
        $this->postJson('/api/v1/vencimientos/politicas', [...$payload, 'modo' => 'si_falta'])->assertOk();
        $this->assertSame(2, DB::table('politicas_renovacion')->count());
        VigenciaLegajo::asegurarRequisitos($ciclo);
        $this->assertSame($reglaOriginal, $ciclo->requerimientos()->sole()->politica_id);
        $this->assertNull($ciclo->requerimientos()->sole()->plazo_dias_habiles);
    }

    public function test_staged_pdf_is_private_and_upload_does_not_restart_period(): void
    {
        [$legajo, $ciclo, $tipo, $requisito] = $this->preparada();
        $this->actuarComo($legajo->usuario, 'personal');
        $url = '/api/v1/vencimientos/ciclos/'.$ciclo->id.'/requisitos/'.$requisito->id.'/pdf';
        $this->post($url, ['pdf' => $this->pdf()], ['Accept' => 'application/json'])->assertCreated();
        $primero = PresentacionRenovacion::query()->sole();
        $this->get('/api/v1/vencimientos/ciclos/'.$ciclo->id.'/presentaciones/'.$primero->id)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->post($url, ['pdf' => $this->pdf()], ['Accept' => 'application/json'])->assertCreated();
        $this->assertSame(2, PresentacionRenovacion::query()->count());
        $this->assertSame(1, PresentacionRenovacion::query()->where('actual', true)->count());
        $this->assertSame('2023-10-16', $ciclo->refresh()->fecha_base->toDateString());
        $this->assertSame(1, DocumentoUsuario::query()->where('legajo_id', $legajo->id)->count());
        $this->post($url, ['pdf' => UploadedFile::fake()->createWithContent('falso.pdf', 'no es un PDF')], ['Accept' => 'application/json'])->assertUnprocessable();
    }

    public function test_incomplete_renewal_is_not_submitted_or_approved(): void
    {
        [$legajo, $ciclo] = $this->preparada();
        $this->postJson('/api/v1/vencimientos/ciclos/'.$ciclo->id.'/presentar')->assertUnprocessable();
        $this->postJson('/api/v1/vencimientos/ciclos/'.$ciclo->id.'/revisar', ['accion' => 'aprobar'])->assertConflict();
        $this->assertSame('2023-10-16', $ciclo->refresh()->fecha_base->toDateString());
    }

    public function test_generic_crud_cannot_modify_or_delete_cycle_requirements(): void
    {
        [, , , $requisito] = $this->preparada();
        $this->patchJson('/api/v1/requerimientos-documentos/'.$requisito->id, ['estado' => 'cumplido'])->assertConflict();
        $this->deleteJson('/api/v1/requerimientos-documentos/'.$requisito->id)->assertConflict();
        $this->assertDatabaseHas('requerimientos_documentos', ['id' => $requisito->id, 'estado' => 'abierto']);
    }

    public function test_full_approved_renewal_restarts_period_once_and_keeps_old_evidence(): void
    {
        [$legajo, $ciclo, $tipo, $requisito] = $this->preparada();
        $nuevoDocumento = $this->evidencia($legajo, $tipo, '2026-10-09');
        $this->getJson('/api/v1/vencimientos/legajos/'.$legajo->id)->assertOk()->assertJsonPath('data.requisitos.0.documentos.0.id', $nuevoDocumento->id);
        $this->postJson('/api/v1/vencimientos/ciclos/'.$ciclo->id.'/requisitos/'.$requisito->id.'/vincular', ['documento_id' => $nuevoDocumento->id])->assertOk();
        $this->postJson('/api/v1/vencimientos/ciclos/'.$ciclo->id.'/presentar')->assertOk();
        $this->postJson('/api/v1/vencimientos/ciclos/'.$ciclo->id.'/revisar', ['accion' => 'aprobar'])->assertOk()->assertJsonPath('data.fecha_vencimiento', '2029-10-09');
        $this->assertFalse($ciclo->refresh()->actual);
        $this->assertSame(1, CicloVigencia::query()->where('actual', true)->where('legajo_id', $legajo->id)->count());
        $this->assertSame(2, DocumentoUsuario::query()->where('legajo_id', $legajo->id)->count());
        $this->assertDatabaseHas('renovaciones_legajo', ['ciclo_id' => $ciclo->id, 'estado' => 'aprobada', 'aprobada_por' => $this->admin->id]);
        $this->postJson('/api/v1/vencimientos/ciclos/'.$ciclo->id.'/revisar', ['accion' => 'aprobar'])->assertConflict();
    }

    public function test_wrong_owner_old_evidence_and_self_review_are_rejected(): void
    {
        [$legajo, $ciclo, $tipo, $requisito] = $this->preparada();
        [$otro] = $this->docente();
        $ajeno = $this->evidencia($otro, $tipo, '2026-10-09');
        $url = '/api/v1/vencimientos/ciclos/'.$ciclo->id.'/requisitos/'.$requisito->id.'/vincular';
        $this->postJson($url, ['documento_id' => $ajeno->id])->assertUnprocessable();
        $propio = $this->evidencia($legajo, $tipo, '2026-10-09', $this->admin);
        $this->postJson($url, ['documento_id' => $propio->id])->assertForbidden();
        $observado = $this->evidencia($legajo, $tipo, '2026-10-09');
        $observado->update(['estado' => 'observado']);
        $this->postJson($url, ['documento_id' => $observado->id])->assertUnprocessable();
    }

    public function test_expiring_evidence_is_rechecked_at_approval(): void
    {
        [$legajo, $ciclo, $tipo, $requisito] = $this->preparada();
        $doc = $this->evidencia($legajo, $tipo, '2026-10-09');
        $this->postJson('/api/v1/vencimientos/ciclos/'.$ciclo->id.'/requisitos/'.$requisito->id.'/vincular', ['documento_id' => $doc->id, 'fecha_vencimiento' => '2026-10-10'])->assertOk();
        $this->postJson('/api/v1/vencimientos/ciclos/'.$ciclo->id.'/presentar')->assertOk();
        $this->travelTo(Carbon::parse('2026-10-10 10:00:00', 'America/Lima'));
        $this->postJson('/api/v1/vencimientos/ciclos/'.$ciclo->id.'/revisar', ['accion' => 'aprobar'])->assertUnprocessable();
        $this->assertTrue($ciclo->refresh()->actual);
    }

    public function test_sensitive_requirement_is_hidden_from_assistant_and_disabled_section_blocks_approval(): void
    {
        [$legajo, $ciclo, $tipo, $requisito] = $this->preparada(10);
        $asistente = $this->actuarComo(null, 'asistente_legajo');
        $this->getJson('/api/v1/vencimientos/legajos/'.$legajo->id)->assertOk()->assertJsonPath('data.requisitos.0.nombre', 'Documento de acceso restringido')->assertJsonPath('data.requisitos.0.permitido', false);
        $this->actuarComo($this->admin);
        $doc = $this->evidencia($legajo, $tipo, '2026-10-09');
        $this->postJson('/api/v1/vencimientos/ciclos/'.$ciclo->id.'/requisitos/'.$requisito->id.'/vincular', ['documento_id' => $doc->id])->assertOk();
        $this->postJson('/api/v1/vencimientos/ciclos/'.$ciclo->id.'/presentar')->assertOk();
        SeccionLegajo::query()->whereKey(10)->update(['activa' => false]);
        $this->postJson('/api/v1/vencimientos/ciclos/'.$ciclo->id.'/revisar', ['accion' => 'aprobar'])->assertUnprocessable();
    }

    public function test_mail_test_transport_is_not_reported_as_delivered_and_archived_cycles_cancel_pending_mail(): void
    {
        $this->assertInstanceOf(ArrayStore::class, Cache::getStore());
        [$legajo, $perfil, $base] = $this->docente();
        $ciclo = VigenciaLegajo::inicializar($legajo, $perfil, $base, $this->admin->id);
        AvisosVencimiento::crear($ciclo);
        $this->assertSame(0, $this->withoutMockingConsoleOutput()->artisan('legajos:enviar-avisos'));
        $this->assertStringContainsString('"simulados":1', Artisan::output());
        $this->assertDatabaseHas('envios_vencimiento', ['estado' => 'simulado', 'enviado_en' => null]);
        $this->assertSame(0, EnvioVencimiento::query()->where('estado', 'enviado')->count());
        EnvioVencimiento::query()->update(['estado' => 'pendiente']);
        $legajo->update(['estado_legajo' => 'pasivo']);
        $this->assertSame(0, $this->withoutMockingConsoleOutput()->artisan('legajos:enviar-avisos'));
        $this->assertDatabaseHas('envios_vencimiento', ['estado' => 'cancelado']);
    }

    public function test_actual_mail_submission_is_tracked_and_manual_retry_does_not_duplicate_event(): void
    {
        [$legajo, $perfil, $base] = $this->docente();
        $ciclo = VigenciaLegajo::inicializar($legajo, $perfil, $base, $this->admin->id);
        $aviso = AvisosVencimiento::crear($ciclo);
        $aviso->envios()->update(['estado' => 'fallido']);
        $this->postJson('/api/v1/vencimientos/ciclos/'.$ciclo->id.'/notificar')->assertStatus(202);
        $this->postJson('/api/v1/vencimientos/ciclos/'.$ciclo->id.'/notificar')->assertConflict();
        $this->assertSame(1, AvisoVencimiento::query()->count());
        config(['mail.default' => 'smtp']);
        Mail::shouldReceive('raw')->once()->andReturn(null);
        AvisosVencimiento::procesar();
        $this->assertDatabaseHas('envios_vencimiento', ['estado' => 'enviado', 'intentos' => 1]);
    }

    private function tipo(int $seccion = 3): TipoDocumento
    {
        return TipoDocumento::factory()->create(['seccion_legajo_id' => $seccion, 'activo' => true, 'acredita_perfil_puesto' => false]);
    }

    public function test_sync_imports_latest_accredited_resolution_and_never_resets_existing_cycle(): void
    {
        [$legajo, $perfil, $base] = $this->docente();
        $nuevo = $this->evidencia($legajo, $base->tipoDocumento, '2024-02-29');
        $nuevo->update(['num_resolucion' => 'RES-002', 'vinculo_laboral_id' => $perfil->vinculo_laboral_id]);
        $this->artisan('legajos:controlar-vencimientos')->assertSuccessful();
        $ciclo = CicloVigencia::query()->where('legajo_id', $legajo->id)->sole();
        $this->assertSame('2024-02-29', $ciclo->fecha_base->toDateString());
        $this->assertSame('2027-02-28', $ciclo->fecha_vencimiento->toDateString());
        $perfil->update(['categoria' => 'principal']);
        $this->artisan('legajos:controlar-vencimientos')->assertSuccessful();
        $this->assertSame('auxiliar', $ciclo->refresh()->categoria);
    }

    public function test_staged_pdf_must_match_formal_evidence_before_approval(): void
    {
        [$legajo, $ciclo, $tipo, $r] = $this->preparada();
        $this->actuarComo($legajo->usuario, 'personal');
        $this->post('/api/v1/vencimientos/ciclos/'.$ciclo->id.'/requisitos/'.$r->id.'/pdf', ['pdf' => $this->pdf()], ['Accept' => 'application/json'])->assertCreated();
        $this->postJson('/api/v1/vencimientos/ciclos/'.$ciclo->id.'/presentar')->assertOk();
        $this->actuarComo($this->admin);
        $doc = $this->evidencia($legajo, $tipo, '2026-10-09');
        $doc->archivo->update(['hash_sha256' => hash('sha256', 'otro pdf')]);
        $url = '/api/v1/vencimientos/ciclos/'.$ciclo->id.'/requisitos/'.$r->id.'/vincular';
        $this->postJson($url, ['documento_id' => $doc->id])->assertUnprocessable();
        $doc->archivo->update(['hash_sha256' => hash('sha256', $this->pdf()->getContent())]);
        $this->postJson($url, ['documento_id' => $doc->id])->assertOk();
        $this->postJson('/api/v1/vencimientos/ciclos/'.$ciclo->id.'/revisar', ['accion' => 'aprobar'])->assertOk();
        $this->assertSame(2, CicloVigencia::query()->where('legajo_id', $legajo->id)->count());
    }

    public function test_fiscalization_requires_an_approved_report_and_a_different_uploader(): void
    {
        [$legajo, $ciclo, $tipo, $r] = $this->preparada();
        $tipo->update(['acredita_perfil_puesto' => true]);
        $doc = $this->evidencia($legajo, $tipo, '2026-10-09');
        $doc->update(['estado' => 'aceptado']);
        $informe = $this->evidencia($legajo, $this->tipo(12), '2026-10-09');
        $detalle = $this->getJson('/api/v1/vencimientos/legajos/'.$legajo->id)->assertOk();
        $detalle->assertJsonPath('data.requisitos.0.documentos.0.necesita_fiscalizacion', true);
        $this->assertContains($informe->id, array_column($detalle->json('data.informes'), 'id'));
        $payload = ['documento_id' => $doc->id, 'informe_documento_id' => $informe->id, 'fecha_inicio' => '2026-10-09', 'conclusiones' => 'Informe de comprobación de autenticidad conforme.', 'conformidad_confirmada' => true];
        $url = '/api/v1/vencimientos/ciclos/'.$ciclo->id.'/requisitos/'.$r->id;
        $this->postJson($url.'/vincular', ['documento_id' => $doc->id])->assertUnprocessable();
        $this->postJson($url.'/fiscalizar', [...$payload, 'conformidad_confirmada' => false])->assertUnprocessable();
        $informe->update(['fecha_recepcion' => null]);
        $this->postJson($url.'/fiscalizar', $payload)->assertUnprocessable();
        $this->assertFalse($doc->refresh()->fiscalizado);
        $informe->update(['fecha_recepcion' => '2026-10-09']);
        $this->postJson($url.'/fiscalizar', $payload)->assertOk();
        $this->assertDatabaseHas('documentos_usuario', ['id' => $doc->id, 'fiscalizado' => true, 'fiscalizado_por' => $this->admin->id, 'estado' => 'verificado']);
        $this->assertDatabaseHas('procesos_fiscalizacion', ['legajo_id' => $legajo->id, 'informe_documento_id' => $informe->id, 'estado' => 'cerrado']);
        $this->assertDatabaseHas('historial_estados_documento', ['documento_usuario_id' => $doc->id, 'estado_nuevo' => 'verificado']);
        $this->postJson($url.'/vincular', ['documento_id' => $doc->id])->assertOk();
        $this->postJson($url.'/fiscalizar', $payload)->assertConflict();
    }

    public function test_fiscalization_cannot_verify_the_uploaders_own_document(): void
    {
        [$legajo, $ciclo, $tipo, $r] = $this->preparada();
        $tipo->update(['acredita_perfil_puesto' => true]);
        $doc = $this->evidencia($legajo, $tipo, '2026-10-09', $this->admin);
        $informe = $this->evidencia($legajo, $this->tipo(12), '2026-10-09');
        $this->postJson('/api/v1/vencimientos/ciclos/'.$ciclo->id.'/requisitos/'.$r->id.'/fiscalizar', ['documento_id' => $doc->id, 'informe_documento_id' => $informe->id,
            'fecha_inicio' => '2026-10-09', 'conclusiones' => 'Resultado de verificación conforme.', 'conformidad_confirmada' => true])->assertForbidden();
        $this->assertFalse($doc->refresh()->fiscalizado);
    }

    public function test_email_failure_is_retained_for_retry_without_duplicate_panel_notification(): void
    {
        [$legajo, $perfil, $base] = $this->docente();
        $ciclo = VigenciaLegajo::inicializar($legajo, $perfil, $base, $this->admin->id);
        AvisosVencimiento::crear($ciclo);
        config(['mail.default' => 'smtp']);
        Mail::shouldReceive('raw')->once()->andThrow(new \RuntimeException('Fallo de conexión de prueba'));
        $resultado = AvisosVencimiento::procesar();
        $this->assertSame(1, $resultado['fallidos']);
        $this->assertSame(1, EnvioVencimiento::query()->count());
        $envio = EnvioVencimiento::query()->sole();
        $this->assertSame('pendiente', $envio->estado);
        $this->assertNotNull($envio->proximo_intento_en);
        $this->assertNull($envio->enviado_en);
    }

    public function test_mail_retries_at_one_five_and_fifteen_minutes_then_fails(): void
    {
        [$legajo, $perfil, $base] = $this->docente();
        $ciclo = VigenciaLegajo::inicializar($legajo, $perfil, $base, $this->admin->id);
        AvisosVencimiento::crear($ciclo);
        config(['mail.default' => 'smtp']);
        Mail::shouldReceive('raw')->times(4)->andThrow(new \RuntimeException('Fallo de conexión de prueba'));
        foreach ([1, 5, 15] as $intento => $espera) {
            AvisosVencimiento::procesar();
            $envio = EnvioVencimiento::query()->sole();
            $this->assertSame($intento + 1, $envio->intentos);
            $this->assertSame('pendiente', $envio->estado);
            $this->assertTrue(now()->addMinutes($espera)->equalTo($envio->proximo_intento_en));
            AvisosVencimiento::procesar();
            $this->assertSame($intento + 1, $envio->refresh()->intentos);
            $this->travel($espera)->minutes();
        }
        AvisosVencimiento::procesar();
        $envio = EnvioVencimiento::query()->sole();
        $this->assertSame(4, $envio->intentos);
        $this->assertSame('fallido', $envio->estado);
        $this->assertNull($envio->proximo_intento_en);
        AvisosVencimiento::procesar();
        $this->assertSame(4, $envio->refresh()->intentos);
    }

    public function test_mail_timeout_requires_reconciliation_without_automatic_resend(): void
    {
        [$legajo, $perfil, $base] = $this->docente();
        $ciclo = VigenciaLegajo::inicializar($legajo, $perfil, $base, $this->admin->id);
        AvisosVencimiento::crear($ciclo);
        config(['mail.default' => 'smtp']);
        Mail::shouldReceive('raw')->once()->andThrow(new \RuntimeException('SMTP timed out'));
        AvisosVencimiento::procesar();
        $this->assertDatabaseHas('envios_vencimiento', ['estado' => 'incierto', 'intentos' => 1, 'proximo_intento_en' => null]);
        AvisosVencimiento::procesar();
        $this->postJson('/api/v1/vencimientos/ciclos/'.$ciclo->id.'/notificar')->assertConflict();
    }

    public function test_notice_recipient_role_queries_are_bounded_per_chunk(): void
    {
        [$legajo, $perfil, $base] = $this->docente();
        $ciclo = VigenciaLegajo::inicializar($legajo, $perfil, $base, $this->admin->id);
        $gestor = $this->asignarRol(Usuario::factory()->create());
        $expirado = $this->asignarRol(Usuario::factory()->create(), 'ADMIN', ['vigente_hasta' => today()->subDay()->toDateString()]);
        Usuario::factory()->count(25)->create();
        $consultasRoles = 0;
        DB::listen(function ($query) use (&$consultasRoles): void {
            if (str_contains($query->sql, 'from "roles"') || str_contains($query->sql, 'from "usuario_roles"')) {
                $consultasRoles++;
            }
        });
        $aviso = AvisosVencimiento::crear($ciclo);
        $this->assertLessThanOrEqual(3, $consultasRoles);
        $this->assertDatabaseHas('notificaciones_usuario', ['aviso_vencimiento_id' => $aviso->id, 'usuario_id' => $gestor->id]);
        $this->assertDatabaseMissing('notificaciones_usuario', ['aviso_vencimiento_id' => $aviso->id, 'usuario_id' => $expirado->id]);
        $this->assertDatabaseHas('notificaciones_usuario', ['aviso_vencimiento_id' => $aviso->id, 'usuario_id' => $legajo->usuario_id]);
    }

    private function docente(string $categoria = 'auxiliar', string $fecha = '2023-10-16'): array
    {
        $usuario = Usuario::factory()->create(['tipo_personal' => 'docente', 'activo' => true]);
        $legajo = Legajo::factory()->create(['usuario_id' => $usuario->id, 'estado_legajo' => 'activo', 'fecha_apertura' => '2026-01-01']);
        $vinculo = VinculoLaboral::factory()->create(['usuario_id' => $usuario->id, 'tipo_personal' => 'docente', 'fecha_inicio' => '2019-01-01']);
        $perfil = PerfilDocente::factory()->create(['vinculo_laboral_id' => $vinculo->id, 'condicion_docente' => 'ordinario', 'categoria' => $categoria]);
        $base = $this->evidencia($legajo, $this->tipo(2), $fecha);
        $base->tipoDocumento->update(['formulario_datos' => 'vinculos_laborales', 'requiere_num_resolucion' => true]);
        $base->update(['num_resolucion' => 'RES-001', 'vinculo_laboral_id' => $vinculo->id]);

        return [$legajo, $perfil, $base];
    }

    private function evidencia(Legajo $legajo, TipoDocumento $tipo, string $fecha, ?Usuario $actor = null): DocumentoUsuario
    {
        $actor ??= $this->cargador;
        $ruta = 'pruebas/'.Str::uuid().'.pdf';
        $contenido = $this->pdf()->getContent();
        Storage::disk('documentos')->put($ruta, $contenido);
        $archivo = ArchivoDocumento::factory()->create(['almacenamiento' => 'documentos', 'storage_path' => $ruta, 'subido_por' => $actor->id, 'hash_sha256' => hash('sha256', $contenido), 'estado_antivirus' => 'limpio']);

        return DocumentoUsuario::factory()->create(['legajo_id' => $legajo->id, 'usuario_id' => $legajo->usuario_id, 'tipo_documento_id' => $tipo->id, 'seccion_legajo_id' => $tipo->seccion_legajo_id,
            'archivo_id' => $archivo->id, 'estado' => 'cargado', 'fecha_emision' => $fecha, 'fecha_incorporacion' => $fecha,
            'fecha_recepcion' => '2026-10-09', 'recepcionado_por' => $this->admin->id, 'created_by' => $actor->id]);
    }

    private function politica(TipoDocumento $tipo): void
    {
        DB::table('politicas_renovacion')->insert(['id' => (string) Str::uuid(), 'categoria' => 'auxiliar', 'tipo_documento_id' => $tipo->id,
            'modo' => 'por_ciclo', 'version' => 1, 'actual' => true, 'configurada_por' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function preparada(int $seccion = 3): array
    {
        [$legajo, $perfil, $base] = $this->docente();
        $tipo = $this->tipo($seccion);
        $this->politica($tipo);
        $ciclo = VigenciaLegajo::inicializar($legajo, $perfil, $base, $this->admin->id);
        $this->postJson('/api/v1/vencimientos/ciclos/'.$ciclo->id.'/preparar')->assertOk();

        return [$legajo, $ciclo, $tipo, $ciclo->requerimientos()->sole()];
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('documento.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Count 1 /Kids [] >>\nendobj\n%%EOF");
    }
}
