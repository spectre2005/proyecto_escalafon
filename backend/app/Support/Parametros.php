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

    public static function formatoNumero(): array
    {
        $valores = ParametroSistema::query()->whereIn('clave', ['numero_legajo_prefijo', 'numero_legajo_digitos'])->pluck('valor', 'clave');

        return ['prefijo' => $valores['numero_legajo_prefijo'] ?? config('legajo.numero_legajo.prefijo'),
            'digitos' => isset($valores['numero_legajo_digitos']) && is_numeric($valores['numero_legajo_digitos']) ? (int) $valores['numero_legajo_digitos'] : (int) config('legajo.numero_legajo.digitos')];
    }
}
