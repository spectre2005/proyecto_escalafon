<?php

namespace App\Jobs;

use App\Models\NotificacionUsuario;
use App\Models\Plataforma\Institucion;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

class EnviarAvisoCorreo implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public string $avisoId) {}

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(): void
    {
        if (! config('avisos.correo_habilitado') || (app()->bound(Institucion::class) && ! app(Institucion::class)->operativa())) {
            return;
        }
        $destino = DB::transaction(function (): ?string {
            $aviso = NotificacionUsuario::query()->with('usuario')->lockForUpdate()->find($this->avisoId);
            if (! $aviso || $aviso->correo_estado !== 'pendiente') {
                return null;
            }
            $usuario = $aviso->usuario;
            if (! $usuario?->activo || ! $usuario->can('sistema.administrar') || ! filter_var($usuario->email_institucional, FILTER_VALIDATE_EMAIL)) {
                $aviso->forceFill(['correo_estado' => 'error_previo', 'correo_error' => 'El destinatario ya no tiene una cuenta administrativa activa con correo válido.'])->save();

                return null;
            }
            if (! in_array(config('mail.default'), ['smtp', 'ses', 'postmark', 'resend'], true) || ! filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL)) {
                $aviso->forceFill(['correo_estado' => 'error_previo', 'correo_error' => 'Configura un transporte de correo externo y un remitente válido antes de enviar.'])->save();

                return null;
            }
            $aviso->forceFill(['correo_estado' => 'enviando', 'correo_intento_en' => now(), 'correo_error' => null])->save();

            return $usuario->email_institucional;
        });
        if ($destino === null) {
            return;
        }
        try {
            Mail::raw('Tienes un aviso administrativo pendiente en el sistema de legajos. Ingresa por el acceso institucional habitual y revisa la bandeja de Avisos. No respondas con datos personales ni adjuntes documentos.', static function ($mensaje) use ($destino): void {
                $mensaje->to($destino)->subject('Aviso administrativo del sistema de legajos');
            });
            NotificacionUsuario::query()->whereKey($this->avisoId)->where('correo_estado', 'enviando')->update(['correo_estado' => 'enviado', 'correo_enviado_en' => now()]);
        } catch (Throwable $error) {
            // El transporte pudo aceptar el mensaje antes de fallar: no repetir una entrega incierta.
            NotificacionUsuario::query()->whereKey($this->avisoId)->where('correo_estado', 'enviando')->update(['correo_estado' => 'incierto', 'correo_error' => 'No se confirmó la entrega. Verifica el proveedor antes de cualquier reenvío.']);
            report($error);
        }
    }
}
