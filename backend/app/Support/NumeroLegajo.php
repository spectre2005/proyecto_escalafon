<?php

namespace App\Support;

use App\Models\Legajo;

/**
 * Número de legajo correlativo. El formato usa los parámetros institucionales y los valores iniciales de config/legajo.php.
 * Se llama dentro de la transacción de apertura, que serializa las aperturas (ver AperturaLegajoController).
 */
final class NumeroLegajo
{
    public static function siguiente(?\DateTimeInterface $fecha = null): string
    {
        $formato = Parametros::formatoNumero();
        $prefijo = str_replace('{anio}', ($fecha ?? now('America/Lima'))->format('Y'), $formato['prefijo']);
        $usados = Legajo::query()->whereRaw("numero_legajo LIKE ? ESCAPE '!'", [str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $prefijo).'%'])->pluck('numero_legajo')
            ->map(fn (string $numero): string => substr($numero, strlen($prefijo)))
            ->filter(fn (string $correlativo): bool => ctype_digit($correlativo))
            ->map(fn (string $correlativo): int => (int) $correlativo);

        $siguiente = (int) $usados->max() + 1;
        abort_if($formato['digitos'] < 1 || $formato['digitos'] > 10 || strlen($prefijo) + $formato['digitos'] > 20 || $siguiente >= 10 ** $formato['digitos'], 409, 'El formato o correlativo de legajo requiere revisión administrativa.');

        return $prefijo.str_pad((string) $siguiente, (int) $formato['digitos'], '0', STR_PAD_LEFT);
    }
}
