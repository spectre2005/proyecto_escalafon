<?php

namespace Tests\Feature;

use App\Models\Legajo;
use App\Models\NotificacionUsuario;
use App\Models\RequerimientoDocumento;
use App\Models\Usuario;
use App\Support\CorreoRequerimiento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Message;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class CorreoRequerimientoTest extends TestCase
{
    use RefreshDatabase;

    private function requerimiento(): RequerimientoDocumento
    {
        $u = Usuario::factory()->create(['email_institucional' => 'docente@example.test']);
        Legajo::factory()->create(['usuario_id' => $u->id, 'estado_legajo' => 'activo']);

        return RequerimientoDocumento::factory()->create(['usuario_id' => $u->id, 'estado' => 'abierto', 'cerrado_en' => null]);
    }

    private function smtp(): void
    {
        config(['mail.default' => 'smtp', 'mail.from.address' => 'escalafon@example.test', 'mail.reply_to.address' => 'respuestas@example.test']);
    }

    public function test_log_transport_never_marks_a_message_as_sent(): void
    {
        config(['mail.default' => 'log']);
        Mail::shouldReceive('raw')->never();
        $n = CorreoRequerimiento::programar($this->requerimiento(), 'Requerimiento', 'Falta documento');
        $this->assertSame('sin_configurar', $n->correo_estado);
        $this->assertSame(0, CorreoRequerimiento::procesar([$n->id]));
        $this->assertNull($n->fresh()->correo_enviado_en);
    }

    public function test_smtp_contains_reply_instructions_and_reference_and_sends_only_once(): void
    {
        $this->smtp();
        $r = $this->requerimiento();
        $n = CorreoRequerimiento::programar($r, 'Requerimiento', 'Presentar constancia');
        Mail::shouldReceive('raw')->once()->andReturnUsing(function (string $body, \Closure $configure) use ($r) {
            $this->assertStringContainsString('No necesitas ingresar al sistema', $body);
            $this->assertStringContainsString('[REQ-'.$r->id.']', $body);
            $message = new Message(new Email);
            $configure($message);
            $this->assertSame('docente@example.test', $message->getSymfonyMessage()->getTo()[0]->getAddress());
            $this->assertSame('respuestas@example.test', $message->getSymfonyMessage()->getReplyTo()[0]->getAddress());

            return Mockery::mock(SentMessage::class);
        });
        $this->assertSame(1, CorreoRequerimiento::procesar([$n->id]));
        $this->assertSame(0, CorreoRequerimiento::procesar([$n->id]));
        $this->assertSame('enviado', $n->fresh()->correo_estado);
        $this->assertNotNull($n->fresh()->correo_enviado_en);
    }

    public function test_failed_transport_is_recorded_without_claiming_delivery_or_immediately_retrying(): void
    {
        $this->smtp();
        $n = CorreoRequerimiento::programar($this->requerimiento(), 'Aviso', 'Pendiente');
        Mail::shouldReceive('raw')->once()->andThrow(new \RuntimeException('Error ficticio'));
        $this->assertSame(0, CorreoRequerimiento::procesar([$n->id]));
        $this->assertSame('error', $n->fresh()->correo_estado);
        $this->assertNull($n->fresh()->correo_enviado_en);
        $this->assertSame(0, CorreoRequerimiento::procesar());
    }

    public function test_closed_requirement_is_not_sent(): void
    {
        $this->smtp();
        $r = $this->requerimiento();
        $n = CorreoRequerimiento::programar($r, 'Aviso', 'Pendiente');
        $r->update(['estado' => 'cancelado', 'cerrado_en' => now()]);
        Mail::shouldReceive('raw')->never();
        $this->assertSame(0, CorreoRequerimiento::procesar([$n->id]));
        $this->assertSame('cancelado', $n->fresh()->correo_estado);
    }

    public function test_authorized_helper_can_register_email_delivery_without_giving_teacher_an_account(): void
    {
        $r = $this->requerimiento();
        $this->actuarComo(codigo: 'asistente_legajo');
        $this->getJson('/api/v1/solicitudes-legajo')->assertOk()->assertJsonFragment(['id' => $r->id]);
        $this->postJson('/api/v1/solicitudes-legajo/'.$r->id.'/registrar-entrega', ['remitente' => 'otro@example.test', 'referencia_correo' => 'Asunto recibido', 'mensaje' => 'Documento adjunto'])->assertUnprocessable();
        $this->postJson('/api/v1/solicitudes-legajo/'.$r->id.'/registrar-entrega', ['remitente' => 'docente@example.test', 'referencia_correo' => 'Asunto recibido', 'mensaje' => 'Documento adjunto'])->assertOk();
        $this->assertSame('en_revision', $r->fresh()->estado);
        $this->assertSame('correo_manual', $r->fresh()->seguimiento[0]['canal']);
        $this->assertSame([], $r->usuario->permisos());
        $this->getJson('/api/v1/equipo-accesos')->assertForbidden();
    }

    public function test_administrator_cannot_grant_the_disabled_teacher_profile(): void
    {
        $this->actuarComo();
        $u = Usuario::factory()->create();
        $this->postJson('/api/v1/equipo-accesos', ['usuario_id' => $u->id, 'perfil' => 'autoservicio_legajo', 'vigente_desde' => today()->toDateString(), 'motivo' => 'Intento de acceso docente'])->assertUnprocessable()->assertJsonValidationErrors('perfil');
    }

    public function test_existing_requirements_and_pending_notices_are_never_queued_or_sent_implicitly(): void
    {
        $this->smtp();
        $r = $this->requerimiento();
        $otro = RequerimientoDocumento::factory()->create(['usuario_id' => $r->usuario_id, 'estado' => 'abierto']);
        $n = CorreoRequerimiento::programar($r, 'Aviso anterior', 'No enviar automáticamente');
        Mail::shouldReceive('raw')->never();
        $this->assertSame(0, CorreoRequerimiento::procesar());
        $this->artisan('legajo:enviar-requerimientos')->assertSuccessful();
        $this->assertSame('pendiente', $n->fresh()->correo_estado);
        $this->assertSame(0, $n->fresh()->correo_intentos);
        $this->assertSame(0, NotificacionUsuario::query()->where('requerimiento_id', $otro->id)->count());
    }
}
