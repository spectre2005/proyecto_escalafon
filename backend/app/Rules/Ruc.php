<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * RUC peruano: 11 dígitos, prefijo de contribuyente (10, 15, 16, 17 o 20) y dígito verificador de SUNAT (módulo 11:
 * pesos 5,4,3,2,7,6,5,4,3,2 sobre los 10 primeros; 11 - resto, y 10 → 0, 11 → 1).
 */
class Ruc implements ValidationRule
{
    private const PESOS = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];

    public static function valido(string $ruc): bool
    {
        if (! preg_match('/^(10|15|16|17|20)\d{9}$/', $ruc)) {
            return false;
        }
        $suma = 0;
        foreach (self::PESOS as $i => $peso) {
            $suma += (int) $ruc[$i] * $peso;
        }
        $digito = 11 - ($suma % 11);

        return ($digito === 10 ? 0 : ($digito === 11 ? 1 : $digito)) === (int) $ruc[10];
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::valido($value)) {
            $fail('El RUC no es válido: deben ser 11 dígitos con el dígito verificador correcto.');
        }
    }
}
