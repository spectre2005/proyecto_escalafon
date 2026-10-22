<?php

namespace App\Support;

use App\Models\ConexionCorreo;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Symfony\Component\Mime\Email;

final class GmailCorreo
{
    public const ALCANCE = 'https://www.googleapis.com/auth/gmail.send';

    public const LECTURA = 'https://www.googleapis.com/auth/gmail.readonly';

    public static function credencialesConfiguradas(): bool
    {
        return (bool) config('services.gmail.client_id') && (bool) config('services.gmail.client_secret');
    }

    public static function conexion(): ?ConexionCorreo
    {
        return Schema::hasTable('conexiones_correo') ? ConexionCorreo::query()->where('clave', 'gmail_institucional')->first() : null;
    }

    public static function listo(): bool
    {
        $c = self::conexion();

        return self::credencialesConfiguradas() && $c?->habilitada && (bool) $c?->refresh_token;
    }

    public static function recepcionLista(): bool
    {
        return self::listo() && in_array(self::LECTURA, self::conexion()?->alcances ?? [], true);
    }

    public static function conectar(string $code, string $redirect, string $verifier, string $autor): void
    {
        $token = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('services.gmail.client_id'), 'client_secret' => config('services.gmail.client_secret'),
            'code' => $code, 'redirect_uri' => $redirect, 'code_verifier' => $verifier, 'grant_type' => 'authorization_code',
        ]);
        if (! $token->successful() || ! $token->json('access_token') || ! $token->json('refresh_token')
            || ! in_array(self::ALCANCE, explode(' ', (string) $token->json('scope')), true)) {
            throw new RuntimeException('No se pudo autorizar Gmail. Concede permiso para enviar correos e intenta nuevamente.');
        }
        $identidad = Http::withToken($token->json('access_token'))->timeout(20)->get('https://openidconnect.googleapis.com/v1/userinfo');
        if (! $identidad->successful() || $identidad->json('email_verified') !== true || ! filter_var($identidad->json('email'), FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('No se pudo verificar el correo institucional.');
        }
        ConexionCorreo::query()->updateOrCreate(['clave' => 'gmail_institucional'], [
            'email' => $identidad->json('email'), 'access_token' => $token->json('access_token'), 'refresh_token' => $token->json('refresh_token'),
            'expira_en' => now()->addSeconds(max(1, (int) $token->json('expires_in', 3600))), 'habilitada' => true, 'conectado_por' => $autor,
            'alcances' => explode(' ', (string) $token->json('scope')), 'recepcion_error' => null,
            'recepcion_revisada_en' => null,
        ]);
    }

    public static function enviar(string $destinatario, string $asunto, string $cuerpo): string
    {
        $c = self::conexion();
        $access = self::token();
        $reply = self::recepcionLista() || ! filter_var(config('services.gmail.reply_to'), FILTER_VALIDATE_EMAIL)
            ? $c->email : config('services.gmail.reply_to');
        $email = (new Email)->from($c->email)->to($destinatario)->replyTo($reply)->subject($asunto)->text($cuerpo);
        $raw = rtrim(strtr(base64_encode($email->toString()), '+/', '-_'), '=');
        $respuesta = Http::withToken($access)->timeout(20)->post('https://gmail.googleapis.com/gmail/v1/users/me/messages/send', ['raw' => $raw]);
        if ($respuesta->status() === 401) {
            $c->update(['access_token' => null, 'expira_en' => null]);
        }
        if (! $respuesta->successful() || ! $respuesta->json('id')) {
            throw new RuntimeException('Gmail no confirmó el envío del requerimiento.');
        }

        return (string) $respuesta->json('id');
    }

    public static function token(): string
    {
        $c = self::conexion();
        if (! self::credencialesConfiguradas() || ! $c?->habilitada || ! $c->refresh_token) {
            throw new RuntimeException('Conecta la cuenta institucional de Gmail.');
        }
        if (! $c->access_token || ! $c->expira_en || $c->expira_en->lte(now()->addMinute())) {
            $token = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
                'client_id' => config('services.gmail.client_id'), 'client_secret' => config('services.gmail.client_secret'),
                'refresh_token' => $c->refresh_token, 'grant_type' => 'refresh_token',
            ]);
            if (! $token->successful() || ! $token->json('access_token')) {
                throw new RuntimeException('Gmail requiere una nueva autorización del administrador.');
            }
            $c->fill(['access_token' => $token->json('access_token'), 'expira_en' => now()->addSeconds(max(1, (int) $token->json('expires_in', 3600)))]);
            if ($token->json('refresh_token')) {
                $c->refresh_token = $token->json('refresh_token');
            }
            $c->save();
        }

        return $c->access_token;
    }

    public static function leer(string $ruta, array $parametros = []): array
    {
        if (! self::recepcionLista()) {
            throw new RuntimeException('Vuelve a autorizar Gmail para leer las respuestas.');
        }
        $response = Http::withToken(self::token())->timeout(20)->get('https://gmail.googleapis.com/gmail/v1/users/me/'.$ruta, $parametros);
        if ($response->status() === 401) {
            self::conexion()?->update(['access_token' => null, 'expira_en' => null]);
        }
        if (! $response->successful()) {
            throw new RuntimeException('No se pudo consultar Gmail. Revisa la conexión de correo.');
        }

        return $response->json();
    }
}
