<?php

namespace App\Support\Antivirus;

use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Revisión antivirus de todo archivo que entra al sistema, antes de guardarlo o procesarlo. Un archivo infectado se
 * rechaza (422) y queda en el log; si el antivirus no responde, la subida se rechaza (503) en vez de guardar un
 * archivo sin revisar. Devuelve el valor de archivos_documento.estado_antivirus: «limpio», o «pendiente» si no hay
 * motor configurado (ANTIVIRUS_DRIVER=ninguno, solo desarrollo).
 */
final class Antivirus
{
    public const MENSAJE_NO_DISPONIBLE = 'El antivirus no está disponible en este momento y el archivo no se guardó. Intenta nuevamente en unos minutos.';

    public static function revisarArchivo(string $ruta, string $campo = 'pdf'): string
    {
        return self::revisar(fn (EscanerAntivirus $motor): ?string => $motor->escanearArchivo($ruta), $campo);
    }

    public static function revisarContenido(string $contenido, string $campo = 'pdf'): string
    {
        return self::revisar(fn (EscanerAntivirus $motor): ?string => $motor->escanearContenido($contenido), $campo);
    }

    /**
     * Para procesos sin usuario (adjuntos de Gmail): devuelve la amenaza o null y deja que AntivirusNoDisponible
     * suba, para que el proceso se reintente sin incorporar nada.
     */
    public static function amenaza(string $contenido): ?string
    {
        $motor = self::motor();
        $amenaza = $motor->activo() ? $motor->escanearContenido($contenido) : null;
        if ($amenaza !== null) {
            Log::warning('Antivirus: archivo rechazado.', ['amenaza' => $amenaza, 'origen' => 'gmail']);
        }

        return $amenaza;
    }

    /** @param  callable(EscanerAntivirus): ?string  $escanear */
    private static function revisar(callable $escanear, string $campo): string
    {
        $motor = self::motor();
        if (! $motor->activo()) {
            return 'pendiente';
        }
        try {
            $amenaza = $escanear($motor);
        } catch (AntivirusNoDisponible $exception) {
            Log::error('Antivirus no disponible.', ['motivo' => $exception->getMessage()]);
            throw new HttpException(503, self::MENSAJE_NO_DISPONIBLE);
        }
        if ($amenaza !== null) {
            Log::warning('Antivirus: archivo rechazado.', ['amenaza' => $amenaza, 'usuario_id' => auth()->id()]);
            throw ValidationException::withMessages([$campo => "El antivirus detectó una amenaza en el archivo ({$amenaza}) y no se guardó."]);
        }

        return 'limpio';
    }

    private static function motor(): EscanerAntivirus
    {
        return app(EscanerAntivirus::class);
    }
}
