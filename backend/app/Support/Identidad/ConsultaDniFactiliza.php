<?php

namespace App\Support\Identidad;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Factiliza: servicio externo de PAGO, no oficial (no es RENIEC ni PIDE). Recibe el DNI en la URL y el token del
 * servidor (identidad.factiliza.token, FACTILIZA_TOKEN); el frontend nunca lo ve. Solo se toman nombres y apellidos de su
 * respuesta: lo demás (ubicación, domicilio…) se descarta y nada se guarda. Cualquier fallo, tiempo agotado o respuesta
 * que no sea la esperada se convierte en ProveedorNoDisponible, sin el DNI en el mensaje ni en el log.
 */
final class ConsultaDniFactiliza implements ConsultaDni
{
    public const URL = 'https://api.factiliza.com/v1/dni/info/';

    /** Sin token no hay proveedor: ProveedorIdentidad devuelve null y la consulta figura como no disponible. */
    public static function configurada(): bool
    {
        return trim((string) config('identidad.factiliza.token')) !== '';
    }

    public function nombre(): string
    {
        return 'factiliza';
    }

    public function esOficial(): bool
    {
        return false;
    }

    public function esSimulado(): bool
    {
        return false;
    }

    public function consultar(string $dni): ResultadoIdentidad
    {
        if (! self::configurada()) {
            throw new ProveedorNoDisponible('Factiliza: falta el token.');
        }
        $espera = max(1, (int) config('identidad.tiempo_espera'));
        try {
            $respuesta = Http::acceptJson()->withToken(trim((string) config('identidad.factiliza.token')))
                ->connectTimeout(min(3, $espera))->timeout($espera)->withoutRedirecting()->get(self::URL.$dni);
        } catch (ConnectionException) {
            // El mensaje de la excepción lleva la URL y, con ella, el DNI: no se propaga.
            throw new ProveedorNoDisponible('Factiliza: sin respuesta (conexión o tiempo agotado).');
        }

        if ($respuesta->status() === 404) {
            return ResultadoIdentidad::sinCoincidencia();
        }
        if (! $respuesta->successful()) {
            // 401/403: token inválido o revocado; 429: límite del plan; 5xx: servicio caído. Solo el código, nunca el cuerpo.
            Log::warning('Consulta de identidad: Factiliza respondió con error.', ['estado' => $respuesta->status()]);
            throw new ProveedorNoDisponible('Factiliza: respuesta con error.');
        }

        $datos = $respuesta->json('data');
        $texto = fn (string $campo, int $maximo): ?string => is_array($datos) && is_string($datos[$campo] ?? null)
            && ($valor = trim($datos[$campo])) !== '' && mb_strlen($valor) <= $maximo ? $valor : null;
        $nombres = $texto('nombres', 120);
        $paterno = $texto('apellido_paterno', 80);
        // La respuesta tiene que ser la de este DNI y traer al menos nombres y apellido paterno.
        if ($respuesta->json('success') !== true || ! is_array($datos) || ($datos['numero'] ?? null) !== $dni || $nombres === null || $paterno === null) {
            Log::warning('Consulta de identidad: Factiliza devolvió una respuesta inesperada.');
            throw new ProveedorNoDisponible('Factiliza: respuesta inesperada.');
        }

        return ResultadoIdentidad::coincidencia($nombres, $paterno, $texto('apellido_materno', 80));
    }
}
