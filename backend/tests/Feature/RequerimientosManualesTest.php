<?php

namespace Tests\Feature;

use App\Models\ConexionCorreo;
use App\Models\Legajo;
use App\Models\RequerimientoDocumento;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use App\Support\CorreoRequerimiento;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RequerimientosManualesTest extends TestCase
{
    use RefreshDatabase;

    private int $estadoGmail = 200;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = $this->actuarComo();
        config(['services.gmail.client_id' => 'ficticio', 'services.gmail.client_secret' => 'ficticio']);
        ConexionCorreo::query()->create(['clave' => 'gmail_institucional', 'email' => 'institucion@gmail.com',
            'access_token' => 'ficticio', 'refresh_token' => 'ficticio', 'expira_en' => now()->addHour(),
            'habilitada' => true, 'conectado_por' => $admin->id]);
        Http::preventStrayRequests();
        Http::fake(['gmail.googleapis.com/gmail/v1/users/me/messages/send' => fn () => Http::response(['id' => 'gmail-confirmado'], $this->estadoGmail)]);
    }

    private function persona(): Usuario
    {
        $u = Usuario::factory()->create(['email_institucional' => fake()->unique()->userName().'@gmail.com']);
        Legajo::factory()->create(['usuario_id' => $u->id, 'estado_legajo' => 'activo']);

        return $u;
    }

    private function datos(Usuario $u, array $tipos): array
    {
        return ['clase' => 'documentos', 'usuario_id' => $u->id, 'tipo_documento_ids' => $tipos,
            'descripcion' => 'Presentar el documento actualizado', 'fecha_limite' => now('America/Lima')->addWeek()->toDateString()];
    }

    public function test_batch_registers_separate_requirements_and_explicit_sends_do_not_drain_old_notices(): void
    {
        $u = $this->persona();
        $viejo = RequerimientoDocumento::factory()->create(['usuario_id' => $u->id, 'estado' => 'abierto', 'cerrado_en' => null]);
        $avisoViejo = CorreoRequerimiento::programar($viejo, 'Aviso antiguo', 'No enviar');
        $tipos = collect(['EXP_CERTIFICADO_TRABAJO', 'EXP_RESOLUCION_FUNCIONES'])->map(fn ($codigo) => TipoDocumento::factory()->create(['activo' => true, 'seccion_legajo_id' => 4, 'codigo' => $codigo]));
        $response = $this->postJson('/api/v1/solicitudes-legajo', $this->datos($u, $tipos->pluck('id')->all()))
            ->assertCreated()->assertJsonCount(2, 'data')->assertJsonCount(2, 'correos')->assertJsonPath('enviados', 0);
        Http::assertNothingSent();
        foreach ($response->json('correos') as $correo) {
            $url = '/api/v1/solicitudes-legajo/'.$correo['requerimiento_id'].'/enviar';
            $this->postJson($url, ['notificacion_id' => $correo['id']])->assertOk()->assertJsonPath('correo.correo_estado', 'enviado');
            $this->postJson($url, ['notificacion_id' => $correo['id']])->assertOk();
        }
        Http::assertSentCount(2);
        $this->assertSame('pendiente', $avisoViejo->fresh()->correo_estado);
        $this->assertSame(0, $avisoViejo->fresh()->correo_intentos);
        foreach ($tipos as $tipo) {
            $this->assertDatabaseHas('requerimientos_documentos', ['usuario_id' => $u->id, 'tipo_documento_id' => $tipo->id,
                'descripcion' => $tipo->nombre.': Presentar el documento actualizado']);
        }
        $this->assertSame([], $u->fresh()->permisos());
    }

    public function test_batch_is_atomic_when_a_document_already_has_a_manual_request(): void
    {
        $u = $this->persona();
        $tipos = collect(['EXP_CERTIFICADO_TRABAJO', 'EXP_RESOLUCION_FUNCIONES'])->map(fn ($codigo) => TipoDocumento::factory()->create(['activo' => true, 'seccion_legajo_id' => 4, 'codigo' => $codigo]));
        RequerimientoDocumento::factory()->create(['usuario_id' => $u->id, 'tipo_documento_id' => $tipos[1]->id,
            'clase' => 'documentos', 'origen' => 'legajo', 'estado' => 'abierto']);
        $this->postJson('/api/v1/solicitudes-legajo', $this->datos($u, $tipos->pluck('id')->all()))->assertConflict();
        $this->assertDatabaseCount('requerimientos_documentos', 1);
        $this->assertDatabaseCount('notificaciones_usuario', 0);
        Http::assertNothingSent();
    }

    public function test_manual_registration_reuses_an_automatic_pending_requirement_without_losing_history(): void
    {
        $u = $this->persona();
        $tipo = TipoDocumento::factory()->create(['activo' => true, 'seccion_legajo_id' => 4, 'codigo' => 'EXP_CERTIFICADO_TRABAJO']);
        $r = RequerimientoDocumento::factory()->create(['usuario_id' => $u->id, 'tipo_documento_id' => $tipo->id,
            'clase' => 'documentos', 'origen' => 'automatico', 'estado' => 'abierto',
            'seguimiento' => [['accion' => 'detección automática', 'mensaje' => 'Histórico']]]);
        $this->postJson('/api/v1/solicitudes-legajo', $this->datos($u, [$tipo->id]))->assertCreated()->assertJsonPath('data.0.id', $r->id);
        $this->assertDatabaseCount('requerimientos_documentos', 1);
        $this->assertCount(2, $r->fresh()->seguimiento);
        $this->assertSame('legajo', $r->fresh()->origen);
        Http::assertNothingSent();
    }

    public function test_personnel_and_list_queries_do_not_trigger_mail_and_dni_is_available_for_search(): void
    {
        $u = $this->persona();
        $otro = $this->persona();
        $r = RequerimientoDocumento::factory()->create(['usuario_id' => $u->id, 'clase' => 'documentos']);
        RequerimientoDocumento::factory()->create(['usuario_id' => $otro->id, 'clase' => 'documentos']);
        $this->getJson('/api/v1/solicitudes-legajo/opciones')->assertOk()->assertJsonFragment([
            'numero_doc_identidad' => $u->numero_doc_identidad, 'email_institucional' => $u->email_institucional]);
        $this->getJson('/api/v1/solicitudes-legajo?q='.$u->numero_doc_identidad)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $r->id);
        $this->artisan('legajo:recordar-solicitudes')->assertSuccessful();
        $this->artisan('legajo:enviar-requerimientos')->assertSuccessful();
        $this->assertSame(0, CorreoRequerimiento::procesar());
        $this->assertDatabaseCount('notificaciones_usuario', 0);
        $eventos = app(Schedule::class)->events();
        foreach ($eventos as $evento) {
            $this->assertStringNotContainsString('legajo:enviar-requerimientos', $evento->command ?? '');
            $this->assertStringNotContainsString('legajo:recordar-solicitudes', $evento->command ?? '');
        }
        Http::assertNothingSent();
    }

    public function test_send_endpoint_rejects_another_requirements_notice_and_teacher_access(): void
    {
        $u = $this->persona();
        $response = $this->postJson('/api/v1/solicitudes-legajo', $this->datos($u, []))->assertCreated();
        $correo = $response->json('correos.0');
        $otro = RequerimientoDocumento::factory()->create(['usuario_id' => $u->id]);
        $this->postJson('/api/v1/solicitudes-legajo/'.$otro->id.'/enviar', ['notificacion_id' => $correo['id']])->assertNotFound();
        Sanctum::actingAs($u);
        $this->postJson('/api/v1/solicitudes-legajo/'.$correo['requerimiento_id'].'/enviar', ['notificacion_id' => $correo['id']])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_sensitive_and_duplicate_document_types_are_rejected_before_registration(): void
    {
        $u = $this->persona();
        $tipo = TipoDocumento::factory()->create(['activo' => true, 'es_dato_sensible' => true, 'seccion_legajo_id' => 1, 'codigo' => 'S01_B_DOCUMENTO_IDENTIDAD']);
        $this->postJson('/api/v1/solicitudes-legajo', $this->datos($u, [$tipo->id, $tipo->id]))->assertUnprocessable();
        $this->actuarComo(codigo: 'asistente_legajo');
        $this->postJson('/api/v1/solicitudes-legajo', $this->datos($u, [$tipo->id]))->assertForbidden();
        $this->assertDatabaseCount('notificaciones_usuario', 0);
        Http::assertNothingSent();
    }

    public function test_failed_manual_send_can_be_retried_explicitly_without_automatic_retry(): void
    {
        $u = $this->persona();
        $correo = $this->postJson('/api/v1/solicitudes-legajo', $this->datos($u, []))->assertCreated()->json('correos.0');
        $this->estadoGmail = 503;
        $url = '/api/v1/solicitudes-legajo/'.$correo['requerimiento_id'].'/enviar';
        $this->postJson($url, ['notificacion_id' => $correo['id']])->assertOk()->assertJsonPath('correo.correo_estado', 'error');
        $this->assertSame(0, CorreoRequerimiento::procesar());
        Http::assertSentCount(1);
        $this->estadoGmail = 200;
        $this->postJson($url, ['notificacion_id' => $correo['id']])->assertOk()->assertJsonPath('correo.correo_estado', 'enviado');
        $this->assertDatabaseHas('notificaciones_usuario', ['id' => $correo['id'], 'correo_intentos' => 2]);
    }
}
