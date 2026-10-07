<?php

namespace App\Support;

final class RelacionTitular
{
    public const OPCIONES = ['servidor', 'conyuge', 'conviviente', 'hijo', 'padre_madre', 'hermano_hermana', 'otro'];

    public const MAXIMO_OTRO = 150;

    /** @return list<string> */
    public static function reglasOtro(): array
    {
        return ['required_if:relacion_titular,otro', 'prohibited_unless:relacion_titular,otro', 'nullable', 'string', 'max:'.self::MAXIMO_OTRO];
    }
}
