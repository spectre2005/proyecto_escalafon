<?php

namespace App\Support;

use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/** Usa exclusivamente la conexión de la institución actual; no acepta remitentes del cliente. */
class CorreoFiscalizacion
{
    public static function estado(): array
    {
        $conexion = GmailCorreo::conexion();

        return ['configurado' => CorreoRequerimiento::configurado(),
            'remitente' => $conexion ? $conexion->email : config('mail.from.address'),
            'proveedor' => $conexion ? 'gmail' : 'smtp'];
    }

    public static function enviar(string $remitente, string $destinatario, string $asunto, string $cuerpo): string
    {
        $estado = self::estado();
        if (! $estado['configurado'] || mb_strtolower((string) $estado['remitente']) !== mb_strtolower($remitente)) {
            throw new RuntimeException('La cuenta institucional cambió o no está disponible.');
        }
        if ($estado['proveedor'] === 'gmail') {
            return GmailCorreo::enviar($destinatario, $asunto, $cuerpo);
        }
        $resultado = Mail::raw($cuerpo, function (Message $mensaje) use ($destinatario, $asunto): void {
            $mensaje->to($destinatario)->subject($asunto)->replyTo(config('mail.reply_to.address'), config('mail.reply_to.name'));
        });
        if (! $resultado || ! $resultado->getMessageId()) {
            throw new RuntimeException('El transporte no confirmó el envío.');
        }

        return $resultado->getMessageId();
    }
}
