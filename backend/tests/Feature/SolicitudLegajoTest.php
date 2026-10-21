<?php

namespace Tests\Feature;

use App\Models\DocumentoUsuario;
use App\Models\Legajo;
use App\Models\NotificacionUsuario;
use App\Models\RequerimientoDocumento;
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
        $u = $this->asignarRol(Usuario::factory()->create(), 'autoservicio_legajo');
        Legajo::factory()->create(['usuario_id' => $u->id, 'estado_legajo' => 'activo']);

        return $u->refresh();
    }

    private function crear(Usuario $u, string $clase = 'documentos'): string
    {
        return $this->postJson('/api/v1/solicitudes-legajo', ['clase' => $clase, 'usuario_id' => $u->id, 'descripcion' => 'Actualizar datos del legajo', 'fecha_limite' => today()->addWeek()->toDateString()])
            ->assertCreated()->json('data.id');
    }

    public function test_owner_can_request_update_but_cannot_create_requests_for_others_or_read_their_history(): void
    {
        $a = $this->titular();
        $b = $this->titular();
        Sanctum::actingAs($a);
        $id = $this->crear($a, 'actualizacion');
        $this->postJson('/api/v1/solicitudes-legajo', ['clase' => 'documentos', 'usuario_id' => $b->id, 'descripcion' => 'Otro legajo', 'fecha_limite' => today()->addWeek()->toDateString()])->assertForbidden();
        Sanctum::actingAs($b);
        $this->getJson('/api/v1/solicitudes-legajo')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/solicitudes-legajo/'.$id.'/responder', ['mensaje' => 'Respuesta ajena'])->assertNotFound();
        $this->getJson('/api/v1/solicitudes-legajo/'.$id.'/archivos/0')->assertNotFound();
    }

    public function test_delivery_observation_resubmission_and_resolution_keep_history(): void
    {
        $admin = $this->actuarComo();
        $u = $this->titular();
        $id = $this->crear($u);
        Sanctum::actingAs($u);
        $this->postJson('/api/v1/solicitudes-legajo/'.$id.'/responder', ['mensaje' => 'Solicito corregir mi dirección'])->assertOk()->assertJsonPath('data.estado', 'en_revision');
        $this->postJson('/api/v1/solicitudes-legajo/'.$id.'/responder', ['mensaje' => 'Duplicado'])->assertConflict();
        $this->postJson('/api/v1/solicitudes-legajo/'.$id.'/gestionar', ['accion' => 'aceptar', 'mensaje' => 'Me apruebo'])->assertForbidden();
        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/solicitudes-legajo/'.$id.'/gestionar', ['accion' => 'observar', 'mensaje' => 'Precisar dirección'])->assertOk();
        Sanctum::actingAs($u);
        $this->postJson('/api/v1/solicitudes-legajo/'.$id.'/responder', ['mensaje' => 'Dirección corregida'])->assertOk();
        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/solicitudes-legajo/'.$id.'/gestionar', ['accion' => 'aceptar', 'mensaje' => 'Datos corregidos desde la ficha'])->assertOk();
        $r = RequerimientoDocumento::findOrFail($id);
        $this->assertSame('aceptado', $r->estado);
        $this->assertCount(5, $r->seguimiento);
        $this->assertNotNull($r->cerrado_en);
        $this->postJson('/api/v1/solicitudes-legajo/'.$id.'/gestionar', ['accion' => 'observar', 'mensaje' => 'Reabrir'])->assertConflict();
    }

    public function test_duplicate_requests_and_reminders_are_rejected_and_extension_cannot_shorten_deadline(): void
    {
        $this->actuarComo();
        $u = $this->titular();
        $id = $this->crear($u);
        $this->postJson('/api/v1/solicitudes-legajo', ['clase' => 'documentos', 'usuario_id' => $u->id, 'descripcion' => 'Actualizar datos del legajo', 'fecha_limite' => today()->addWeek()->toDateString()])->assertConflict();
        $this->postJson('/api/v1/solicitudes-legajo/'.$id.'/gestionar', ['accion' => 'recordar', 'mensaje' => 'Recordatorio'])->assertOk();
        $this->postJson('/api/v1/solicitudes-legajo/'.$id.'/gestionar', ['accion' => 'recordar', 'mensaje' => 'Duplicado'])->assertConflict();
        $this->postJson('/api/v1/solicitudes-legajo/'.$id.'/gestionar', ['accion' => 'prorrogar', 'mensaje' => 'Plazo', 'prorroga_hasta' => today()->toDateString()])->assertUnprocessable();
    }

    public function test_pdf_is_private_and_a_closed_legajo_rejects_delivery(): void
    {
        Storage::fake(config('legajo.disco_documentos'));
        $this->actuarComo();
        $u = $this->titular();
        $id = $this->crear($u);
        Sanctum::actingAs($u);
        $this->post('/api/v1/solicitudes-legajo/'.$id.'/responder', ['mensaje' => 'Adjunto', 'pdf' => UploadedFile::fake()->createWithContent('evidencia.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Pages /Count 1 >>\nendobj\n%%EOF")], ['Accept' => 'application/json'])->assertOk();
        $this->getJson('/api/v1/solicitudes-legajo')->assertOk()->assertJsonMissingPath('data.0.seguimiento.1.ruta')->assertJsonMissingPath('data.0.seguimiento.1.disco');
        $this->get('/api/v1/solicitudes-legajo/'.$id.'/archivos/1')->assertOk();
        $r = RequerimientoDocumento::findOrFail($id);
        $r->update(['estado' => 'observado']);
        Legajo::where('usuario_id', $u->id)->update(['estado_legajo' => 'cerrado']);
        $this->postJson('/api/v1/solicitudes-legajo/'.$id.'/responder', ['mensaje' => 'Intento'])->assertConflict();
    }

    public function test_daily_reminders_only_include_pending_active_legajos_and_are_idempotent(): void
    {
        $this->actuarComo();
        $u = $this->titular();
        $id = $this->crear($u);
        RequerimientoDocumento::whereKey($id)->update(['fecha_limite' => today()->toDateString()]);
        $this->artisan('legajo:recordar-solicitudes')->assertSuccessful();
        $this->artisan('legajo:recordar-solicitudes')->assertSuccessful();
        $this->assertCount(2, RequerimientoDocumento::findOrFail($id)->seguimiento);
        $this->assertSame(2, NotificacionUsuario::query()->where('requerimiento_id', $id)->count());
        $this->travel(1)->days();
        Legajo::where('usuario_id', $u->id)->update(['estado_legajo' => 'cerrado']);
        $this->artisan('legajo:recordar-solicitudes')->assertSuccessful();
        $this->assertSame(2, NotificacionUsuario::query()->where('requerimiento_id', $id)->count());
    }

    public function test_autoservicio_profile_supports_session_without_access_to_other_legajos(): void
    {
        $u = $this->titular();
        $this->actuarComo($u, 'autoservicio_legajo');
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.permisos', ['solicitudes.propias']);
        $this->getJson('/api/v1/expedientes')->assertForbidden();
        $this->getJson('/api/v1/solicitudes-legajo/opciones')->assertOk()->assertJsonCount(1, 'personas');
        $this->crear($u, 'actualizacion');
    }

    public function test_document_requests_require_a_matching_accepted_document_before_closure(): void
    {
        $admin = $this->actuarComo();
        $u = $this->titular();
        $tipo = TipoDocumento::factory()->create();
        $id = $this->postJson('/api/v1/solicitudes-legajo', ['clase' => 'documentos', 'usuario_id' => $u->id,
            'descripcion' => 'Presentar constancia', 'tipo_documento_id' => $tipo->id,
            'fecha_limite' => today()->addWeek()->toDateString()])->assertCreated()->json('data.id');
        Sanctum::actingAs($u);
        $this->postJson('/api/v1/solicitudes-legajo/'.$id.'/responder', ['mensaje' => 'Entregado presencialmente'])->assertOk();
        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/solicitudes-legajo/'.$id.'/gestionar', ['accion' => 'aceptar', 'mensaje' => 'Revisado'])->assertUnprocessable()->assertJsonValidationErrors('documento_id');
        $doc = DocumentoUsuario::factory()->create(['usuario_id' => $u->id,
            'legajo_id' => Legajo::where('usuario_id', $u->id)->value('id'), 'tipo_documento_id' => $tipo->id,
            'seccion_legajo_id' => $tipo->seccion_legajo_id, 'estado' => 'aceptado']);
        $this->postJson('/api/v1/solicitudes-legajo/'.$id.'/gestionar', ['accion' => 'aceptar', 'mensaje' => 'Revisado', 'documento_id' => $doc->id])->assertOk();
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
        Sanctum::actingAs($u);
        $this->post('/api/v1/solicitudes-legajo/'.$id.'/responder', ['mensaje' => 'Adjunto', 'pdf' => UploadedFile::fake()->createWithContent('falso.pdf', 'No es PDF')], ['Accept' => 'application/json'])->assertUnprocessable();
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
