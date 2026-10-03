<?php

namespace App\Support;

use App\Models\Legajo;

/**
 * Número de legajo correlativo. El formato vive solo en config/legajo.php (numero_legajo).
 * Se llama dentro de la transacción de apertura, que serializa las aperturas (ver AperturaLegajoController).
 */
final class NumeroLegajo
{
    public static function siguiente(?\DateTimeInterface $fecha = null): string
    {
        $formato = config('legajo.numero_legajo');
        $prefijo = str_replace('{anio}', ($fecha ?? today())->format('Y'), $formato['prefijo']);
        $usados = Legajo::query()->where('numero_legajo', 'like', $prefijo.'%')->pluck('numero_legajo')
            ->map(fn (string $numero): string => substr($numero, strlen($prefijo)))
            ->filter(fn (string $correlativo): bool => ctype_digit($correlativo))
            ->map(fn (string $correlativo): int => (int) $correlativo);

        return $prefijo.str_pad((string) ($usados->max() + 1), (int) $formato['digitos'], '0', STR_PAD_LEFT);
    }
}
