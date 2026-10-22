<?php

namespace App\Support;

use App\Models\NotificacionUsuario;
use App\Models\RequerimientoDocumento;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

final class CorreoRequerimiento
{
    public static function smtpConfigurado(): bool
    {
        return config('mail.mailers.'.config('mail.default').'.transport') === 'smtp'
            && filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL)
            && filter_var(config('mail.reply_to.address'), FILTER_VALIDATE_EMAIL);
    }

    public static function configurado(): bool
    {
        return GmailCorreo::conexion() ? GmailCorreo::listo() : self::smtpConfigurado();
    }

    public static function programar(RequerimientoDocumento $r, string $titulo, string $mensaje): NotificacionUsuario
    {
        $correo = $r->usuario()->value('email_institucional');
        $valido = filter_var($correo, FILTER_VALIDATE_EMAIL);

        return NotificacionUsuario::query()->create(['usuario_id' => $r->usuario_id, 'tipo' => 'solicitud_legajo',
            'titulo' => $titulo, 'mensaje' => $mensaje, 'requerimiento_id' => $r->id, 'correo_destinatario' => $correo,
            'correo_estado' => $valido ? (self::configurado() ? 'pendiente' : 'sin_configurar') : 'sin_correo',
            'correo_error' => $valido ? null : 'La persona no tiene un correo válido. Corrígelo antes de enviar.']);
    }

    /** Envía únicamente los avisos elegidos expresamente por administración. */
    public static function procesar(array $notificaciones = []): int
    {
        if ($notificaciones === [] || ! self::configurado()) {
            return 0;
        }
        $enviados = 0;
        NotificacionUsuario::query()->whereIn('id', $notificaciones)->whereIn('correo_estado', ['pendiente', 'sin_configurar', 'error', 'sin_correo'])
            ->select('id')->chunkById(50, function ($filas) use (&$enviados): void {
                foreach ($filas as $fila) {
                    $enviados += DB::transaction(function () use ($fila): int {
                        $n = NotificacionUsuario::query()->with('requerimiento')->lockForUpdate()->findOrFail($fila->id);
                        if (! in_array($n->correo_estado, ['pendiente', 'sin_configurar', 'error', 'sin_correo'], true)) {
                            return 0;
                        }
                        $r = $n->requerimiento;
                        if (! $r || $r->cerrado_en || ! in_array($r->estado, ['abierto', 'observado'], true) || $r->usuario()->whereHas('legajos', fn ($q) => $q->where('estado_legajo', 'activo'))->doesntExist()) {
                            $n->update(['correo_estado' => 'cancelado', 'correo_error' => 'El requerimiento o el legajo están cerrados.']);

                            return 0;
                        }
                        $actual = $r->usuario()->value('email_institucional');
                        if (! filter_var($actual, FILTER_VALIDATE_EMAIL)) {
                            $n->update(['correo_estado' => 'sin_correo', 'correo_error' => 'Completa el correo del docente en Personal.']);

                            return 0;
                        }
                        $n->correo_destinatario = $actual;
                        $n->correo_intentos++;
                        try {
                            $referencia = '[REQ-'.$r->id.']';
                            $cuerpo = $n->mensaje."\n\nRequerimiento: ".$r->descripcion."\nFecha límite: ".($r->prorroga_hasta ?? $r->fecha_limite)->format('d/m/Y')
                                ."\n\nResponde a este mismo correo y adjunta el documento solicitado o explica por qué no corresponde. No necesitas ingresar al sistema ni crear una cuenta."
                                ."\nConserva la referencia ".$referencia.' en el asunto para identificar tu entrega.';
                            $proveedorId = null;
                            if (GmailCorreo::conexion()) {
                                $proveedorId = GmailCorreo::enviar($n->correo_destinatario, $referencia.' '.$n->titulo, $cuerpo);
                            } else {
                                $resultado = Mail::raw($cuerpo, function (Message $mail) use ($n, $referencia): void {
                                    $mail->to($n->correo_destinatario)->subject($referencia.' '.$n->titulo)
                                        ->replyTo(config('mail.reply_to.address'), config('mail.reply_to.name'));
                                });
                                if (! $resultado) {
                                    throw new RuntimeException('El transporte no confirmó el envío.');
                                }
                            }
                            $n->fill(['correo_id_proveedor' => $proveedorId, 'correo_estado' => 'enviado', 'correo_enviado_en' => now(), 'correo_error' => null, 'correo_reintentar_en' => null])->save();

                            return 1;
                        } catch (Throwable) {
                            $n->fill(['correo_estado' => 'error', 'correo_error' => 'No se pudo confirmar el envío. Revisa la conexión de correo en Configuración.', 'correo_reintentar_en' => null])->save();

                            return 0;
                        }
                    });
                }
            });

        return $enviados;
    }
}
