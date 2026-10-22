<?php

namespace Tests\Feature;

use App\Models\DocumentoUsuario;
use App\Models\Legajo;
use App\Models\NotificacionUsuario;
use App\Models\RequerimientoDocumento;
use App\Models\Rol;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SolicitudLegajoTest extends TestCase
{
    use RefreshDatabase;

    private function titular(): Usuario
    {
        $u = Usuario::factory()->create();
        Legajo::factory()->create(['usuario_id' => $u->id, 'estado_legajo' => 'activo']);

        return $u->refresh();
    }

    private function crear(Usuario $u, string $clase = 'documentos'): string
    {
        return $this->postJson('/api/v1/solicitudes-legajo', ['clase' => $clase, 'usuario_id' => $u->id, 'descripcion' => 'Actualizar datos del legajo', 'fecha_limite' => today()->addWeek()->toDateString()])
            ->assertCreated()->json('data.id');
    }

    public function test_teachers_without_accounts_cannot_access_requirements_or_submit_through_the_platform(): void
    {
        $this->actuarComo();
        $u = $this->titular();
        $id = $this->crear($u);
        Sanctum::actingAs($u);
        $this->getJson('/api/v1/solicitudes-legajo')->assertForbidden();
        $this->postJson('/api/v1/solicitudes-legajo/'.$id.'/registrar-entrega', ['remitente' => $u->email_institucional, 'referencia_correo' => 'Correo recibido de prueba', 'mensaje' => 'Intento'])->assertForbidden();
        $this->getJson('/api/v1/solicitudes-legajo/'.$id.'/archivos/0')->assertForbidden();
    }

    public function test_delivery_observation_resubmission_and_resolution_keep_history(): void
    {
        $admin = $this->actuarComo();
        $u = $this->titular();
        $id = $this->crear($u);
        $this->postJson('/api/v1/solicitudes-legajo/'.$id.'/registrar-entrega', ['remitente' => $u->email_institucional, 'referencia_correo' => 'Correo recibido de prueba', 'mensaje' => 'Solicito corregir mi dirección'])->assertOk()->assertJsonPath('data.estado', 'en_revision');
        $this->postJson('/api/v1/solicitudes-legajo/'.$id.'/registrar-entrega', ['remitente' => $u->email_institucional, 'referencia_correo' => 'Correo recibido de prueba', 'mensaje' => 'Duplicado'])->assertConflict();
        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/solicitudes-legajo/'.$id.'/gestionar', ['accion' => 'observar', 'remitente' => $u->email_institucional, 'referencia_correo' => 'Correo recibido de prueba', 'mensaje' => 'Precisar dirección'])->assertOk();
        $this->postJson('/api/v1/solicitudes-legajo/'.$id.'/registrar-entrega', ['remitente' => $u->email_institucional, 'referencia_correo' => 'Correo recibido de prueba', 'mensaje' => 'Dirección corregida'])->assertOk();
        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/solicitudes-legajo/'.$id.'/gestionar', ['accion' => 'aceptar', 'remitente' => $u->email_institucional, 'referencia_correo' => 'Correo recibido de prueba', 'mensaje' => 'Datos corregidos desde la ficha'])->assertOk();
        $r = RequerimientoDocumento::findOrFail($id);
        $this->assertSame('aceptado', $r->estado);
        $this->assertCount(5, $r->seguimiento);
        $this->assertNotNull($r->cerrado_en);
        $this->postJson('/api/v1/solicitudes-legajo/'.$id.'/gestionar', ['accion' => 'observar', 'remitente' => $u->email_institucional, 'referencia_correo' => 'Correo recibido de prueba', 'mensaje' => 'Reabrir'])->assertConflict();
    }

    public function test_duplicate_requests_and_reminders_are_rejected_and_extension_cannot_shorten_deadline(): void
    {
        $this->actuarComo();
        $u = $this->titular();
        $id = $this->crear($u);
        $this->postJson('/api/v1/solicitudes-legajo', ['clase' => 'documentos', 'usuario_id' => $u->id, 'descripcion' => 'Actualizar datos del legajo', 'fecha_limite' => today()->addWeek()->toDateString()])->assertConflict();
        $this->postJson('/api/v1/solicitudes-legajo/'.$id.'/gestionar', ['accion' => 'recordar', 'remitente' => $u->email_institucional, 'referencia_correo' => 'Correo recibido de prueba', 'mensaje' => 'Recordatorio'])->assertOk();
        $this->postJson('/api/v1/solicitudes-legajo/'.$id.'/gestionar', ['accion' => 'recordar', 'remitente' => $u->email_institucional, 'referencia_correo' => 'Correo recibido de prueba', 'mensaje' => 'Duplicado'])->assertConflict();
        $this->postJson('/api/v1/solicitudes-legajo/'.$id.'/gestionar', ['accion' => 'prorrogar', 'remitente' => $u->email_institucional, 'referencia_correo' => 'Correo recibido de prueba', 'mensaje' => 'Plazo', 'prorroga_hasta' => today()->toDateString()])->assertUnprocessable();
    }

    public function test_pdf_is_private_and_a_closed_legajo_rejects_delivery(): void
    {
        Storage::fake(config('legajo.disco_documentos'));
        $this->actuarComo();
        $u = $this->titular();
        $id = $this->crear($u);
        $this->post('/api/v1/solicitudes-legajo/'.$id.'/registrar-entrega', ['remitente' => $u->email_institucional, 'referencia_correo' => 'Correo recibido de prueba', 'mensaje' => 'Adjunto', 'pdf' => UploadedFile::fake()->createWithContent('evidencia.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Pages /Count 1 >>\nendobj\n%%EOF")], ['Accept' => 'application/json'])->assertOk();
        $this->getJson('/api/v1/solicitudes-legajo')->assertOk()->assertJsonMissingPath('data.0.seguimiento.1.ruta')->assertJsonMissingPath('data.0.seguimiento.1.disco');
        $this->get('/api/v1/solicitudes-legajo/'.$id.'/archivos/1')->assertOk();
        $r = RequerimientoDocumento::findOrFail($id);
        $r->update(['estado' => 'observado']);
        Legajo::where('usuario_id', $u->id)->update(['estado_legajo' => 'cerrado']);
        $this->postJson('/api/v1/solicitudes-legajo/'.$id.'/registrar-entrega', ['remitente' => $u->email_institucional, 'referencia_correo' => 'Correo recibido de prueba', 'mensaje' => 'Intento'])->assertConflict();
    }

    public function test_daily_command_does_not_create_or_send_reminders(): void
    {
        $this->actuarComo();
        $u = $this->titular();
        $id = $this->crear($u);
        RequerimientoDocumento::whereKey($id)->update(['fecha_limite' => today()->toDateString()]);
        $this->artisan('legajo:recordar-solicitudes')->assertSuccessful();
        $this->artisan('legajo:recordar-solicitudes')->assertSuccessful();
        $this->assertCount(1, RequerimientoDocumento::findOrFail($id)->seguimiento);
        $this->assertSame(1, NotificacionUsuario::query()->where('requerimiento_id', $id)->count());
        $this->travel(1)->days();
        Legajo::where('usuario_id', $u->id)->update(['estado_legajo' => 'cerrado']);
        $this->artisan('legajo:recordar-solicitudes')->assertSuccessful();
        $this->assertSame(1, NotificacionUsuario::query()->where('requerimiento_id', $id)->count());
    }

    public function test_old_autoservicio_profile_no_longer_allows_a_session(): void
    {
        $u = $this->titular();
        $this->actuarComo($u, 'autoservicio_legajo');
        Rol::query()->where('codigo', 'autoservicio_legajo')->update(['ve_datos_sensibles' => true]);
        config(['sanctum.stateful' => ['localhost:5173']]);
        $this->withHeader('Origin', 'http://localhost:5173')->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->assertSame([], $u->fresh()->permisos());
    }

    public function test_document_requests_require_a_matching_accepted_document_before_closure(): void
    {
        $admin = $this->actuarComo();
        $u = $this->titular();
        $tipo = TipoDocumento::factory()->create();
        $id = $this->postJson('/api/v1/solicitudes-legajo', ['clase' => 'documentos', 'usuario_id' => $u->id,
            'descripcion' => 'Presentar constancia', 'tipo_documento_id' => $tipo->id,
            'fecha_limite' => today()->addWeek()->toDateString()])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/solicitudes-legajo/'.$id.'/registrar-entrega', ['remitente' => $u->email_institucional, 'referencia_correo' => 'Correo recibido de prueba', 'mensaje' => 'Entregado presencialmente'])->assertOk();
        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/solicitudes-legajo/'.$id.'/gestionar', ['accion' => 'aceptar', 'remitente' => $u->email_institucional, 'referencia_correo' => 'Correo recibido de prueba', 'mensaje' => 'Revisado'])->assertUnprocessable()->assertJsonValidationErrors('documento_id');
        $doc = DocumentoUsuario::factory()->create(['usuario_id' => $u->id,
            'legajo_id' => Legajo::where('usuario_id', $u->id)->value('id'), 'tipo_documento_id' => $tipo->id,
            'seccion_legajo_id' => $tipo->seccion_legajo_id, 'estado' => 'aceptado']);
        $this->postJson('/api/v1/solicitudes-legajo/'.$id.'/gestionar', ['accion' => 'aceptar', 'remitente' => $u->email_institucional, 'referencia_correo' => 'Correo recibido de prueba', 'mensaje' => 'Revisado', 'documento_id' => $doc->id])->assertOk();
        $this->assertDatabaseHas('requerimientos_documentos', ['id' => $id, 'estado' => 'aceptado', 'documento_id' => $doc->id]);
    }

    public function test_generic_api_cannot_erase_or_overwrite_workflow_history(): void
    {
        $this->actuarComo();
        $id = $this->crear($this->titular());
        $this->patchJson('/api/v1/requerimientos-documentos/'.$id, ['estado' => 'aceptado'])->assertConflict();
        $this->deleteJson('/api/v1/requerimientos-documentos/'.$id)->assertConflict();
        $this->assertDatabaseHas('requerimientos_documentos', ['id' => $id, 'estado' => 'abierto']);
    }

    public function test_renamed_non_pdf_upload_is_rejected_without_changing_state(): void
    {
        $this->actuarComo();
        $u = $this->titular();
        $id = $this->crear($u);
        $this->post('/api/v1/solicitudes-legajo/'.$id.'/registrar-entrega', ['remitente' => $u->email_institucional, 'referencia_correo' => 'Correo recibido de prueba', 'mensaje' => 'Adjunto', 'pdf' => UploadedFile::fake()->createWithContent('falso.pdf', 'No es PDF')], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertDatabaseHas('requerimientos_documentos', ['id' => $id, 'estado' => 'abierto']);
    }

    public function test_deadlines_use_peruvian_date_even_after_midnight_utc(): void
    {
        $this->travelTo(Carbon::parse('2026-10-22 01:00:00', 'UTC'));
        $this->actuarComo();
        $u = $this->titular();
        $this->postJson('/api/v1/solicitudes-legajo', ['clase' => 'documentos', 'usuario_id' => $u->id,
            'descripcion' => 'Plazo de hoy en Perú', 'fecha_limite' => '2026-10-21'])
            ->assertCreated()->assertJsonPath('data.fecha_requerimiento', fn (string $fecha): bool => str_starts_with($fecha, '2026-10-21'));
        $this->getJson('/api/v1/solicitudes-legajo')->assertOk()->assertJsonPath('data.0.atrasado', false);
    }
}
