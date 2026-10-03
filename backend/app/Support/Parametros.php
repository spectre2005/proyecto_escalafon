<?php

namespace App\Support;

use App\Models\ParametroSistema;

/**
 * Parámetros configurables: primero parametros_sistema (lo que ajusta la ORH del cliente),
 * luego config/legajo.php (valor por defecto del producto).
 */
final class Parametros
{
    public static function entero(string $clave): int
    {
        $valor = ParametroSistema::query()->where('clave', $clave)->value('valor');

        return is_numeric($valor) ? (int) $valor : (int) config('legajo.'.$clave);
    }
}
