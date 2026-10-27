<?php

namespace App\Support;

/**
 * Tamaño de página común de los listados (`por_pagina`): 10 por defecto y opciones 10, 15 y 20. Un listado con una
 * excepción justificada pasa sus propias opciones. La lista es cerrada: el cliente no puede pedir páginas mayores.
 */
final class PorPagina
{
    public const OPCIONES = [10, 15, 20];

    public const POR_DEFECTO = 10;

    /** @param  list<int>  $opciones */
    public static function regla(array $opciones = self::OPCIONES): string
    {
        return 'sometimes|integer|in:'.implode(',', $opciones);
    }

    /** @param  array<string, mixed>  $datos  ya validados con regla() */
    public static function de(array $datos, int $porDefecto = self::POR_DEFECTO): int
    {
        return (int) ($datos['por_pagina'] ?? $porDefecto);
    }
}
