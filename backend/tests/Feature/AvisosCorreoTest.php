<?php

namespace Tests\Feature;

use App\Jobs\EnviarAvisoCorreo;
use App\Jobs\GenerarAvisosInstitucion;
use App\Models\NotificacionUsuario;
use App\Support\GeneradorAvisos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Concerns\OperacionesAdministrativas;
use Tests\TestCase;

class AvisosCorreoTest extends TestCase
{
    use OperacionesAdministrativas, RefreshDatabase;

    public function test_email_is_disabled_by_default_and_duplicate_jobs_cannot_resend(): void
    {
        $actor = $this->prepararOperaciones();
        $aviso = NotificacionUsuario::factory()->create(['usuario_id' => $actor->id]);
        Mail::fake();
        (new EnviarAvisoCorreo($aviso->id))->handle();
        Mail::assertNothingSent();
        $this->assertSame('pendiente', $aviso->refresh()->correo_estado);
        config(['avisos.correo_habilitado' => true, 'mail.default' => 'smtp', 'mail.from.address' => 'avisos@example.test']);
        Mail::shouldReceive('raw')->once()->andReturn(null);
        (new EnviarAvisoCorreo($aviso->id))->handle();
        (new EnviarAvisoCorreo($aviso->id))->handle();
        $this->assertSame('enviado', $aviso->refresh()->correo_estado);
    }

    public function test_uncertain_delivery_is_not_automatically_repeated_and_preflight_errors_can_be_retried(): void
    {
        $actor = $this->prepararOperaciones();
        config(['avisos.correo_habilitado' => true, 'mail.default' => 'smtp', 'mail.from.address' => 'avisos@example.test']);
        $aviso = NotificacionUsuario::factory()->create(['usuario_id' => $actor->id]);
        Mail::shouldReceive('raw')->once()->andThrow(new RuntimeException('Transporte interrumpido'));
        (new EnviarAvisoCorreo($aviso->id))->handle();
        (new EnviarAvisoCorreo($aviso->id))->handle();
        $this->assertSame('incierto', $aviso->refresh()->correo_estado);
        $this->postJson('/api/v1/avisos/'.$aviso->id.'/reintentar-correo', ['motivo' => 'Intentar de nuevo'])->assertConflict();
        $otro = NotificacionUsuario::factory()->create(['usuario_id' => $actor->id]);
        config(['mail.default' => 'log']);
        (new EnviarAvisoCorreo($otro->id))->handle();
        $this->assertSame('error_previo', $otro->refresh()->correo_estado);
        Queue::fake();
        $this->postJson('/api/v1/avisos/'.$otro->id.'/reintentar-correo', ['motivo' => 'Configuración corregida por operador'])->assertOk();
        $this->assertSame('pendiente', $otro->refresh()->correo_estado);
        $this->postJson('/api/v1/avisos/'.$otro->id.'/reintentar-correo', ['motivo' => 'No duplicar el envío pendiente'])->assertConflict();
    }

    public function test_queued_generation_is_idempotent_and_queues_email_only_when_explicitly_enabled(): void
    {
        $this->prepararOperaciones();
        $doc = $this->documentoOperativo($this->legajoOperativo());
        $doc->update(['fecha_caducidad' => $this->fechaOperacion(3)]);
        Queue::fake();
        (new GenerarAvisosInstitucion)->handle();
        $this->assertSame(1, NotificacionUsuario::query()->count());
        Queue::assertNothingPushed();
        config(['avisos.correo_habilitado' => true]);
        $this->assertSame(0, GeneradorAvisos::generar());
        Queue::assertPushed(EnviarAvisoCorreo::class);
        $this->artisan('avisos:generar', ['--cola' => true])->assertSuccessful();
        Queue::assertPushed(GenerarAvisosInstitucion::class);
    }
}
