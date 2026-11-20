<?php

namespace App\Support;

use Illuminate\Support\Facades\RateLimiter;

/**
 * Límite de intentos fallidos de inicio de sesión, compartido por el sistema de cada institución y el panel de la
 * plataforma. Dos contadores:
 * - por correo e IP: 5 intentos en 60 s (el uso normal: alguien que se equivoca de contraseña);
 * - por cuenta, desde cualquier IP: 20 intentos en 15 min, para que quien cambia de IP no pueda seguir probando claves
 *   contra la misma cuenta. Es más alto a propósito: así un tercero no bloquea fácilmente a la persona real.
 * Un inicio correcto reinicia ambos.
 */
final class LimiteInicioSesion
{
    public const INTENTOS_POR_IP = 5;

    public const SEGUNDOS_POR_IP = 60;

    public const INTENTOS_POR_CUENTA = 20;

    public const SEGUNDOS_POR_CUENTA = 900;

    /** Segundos de espera si se superó alguno de los límites, o null si se puede intentar. */
    public static function espera(string $ambito, string $email, ?string $ip): ?int
    {
        foreach (self::claves($ambito, $email, $ip) as $clave => $maximo) {
            if (RateLimiter::tooManyAttempts($clave, $maximo)) {
                return RateLimiter::availableIn($clave);
            }
        }

        return null;
    }

    public static function fallo(string $ambito, string $email, ?string $ip): void
    {
        RateLimiter::hit(self::clave($ambito, $email, $ip), self::SEGUNDOS_POR_IP);
        RateLimiter::hit(self::clave($ambito, $email), self::SEGUNDOS_POR_CUENTA);
    }

    public static function exito(string $ambito, string $email, ?string $ip): void
    {
        foreach (array_keys(self::claves($ambito, $email, $ip)) as $clave) {
            RateLimiter::clear($clave);
        }
    }

    /** @return array<string, int> clave => intentos máximos */
    private static function claves(string $ambito, string $email, ?string $ip): array
    {
        return [self::clave($ambito, $email, $ip) => self::INTENTOS_POR_IP, self::clave($ambito, $email) => self::INTENTOS_POR_CUENTA];
    }

    /** La clave por IP conserva el formato anterior (login:correo|ip) para no reiniciar contadores en curso. */
    private static function clave(string $ambito, string $email, ?string $ip = null): string
    {
        $prefijo = $ambito === 'plataforma' ? 'plataforma-login' : 'login';

        return $ip === null ? $prefijo.'-cuenta:'.$email : $prefijo.':'.$email.'|'.$ip;
    }
}
