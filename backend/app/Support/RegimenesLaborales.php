<?php

namespace App\Support;

/**
 * Regímenes laborales admitidos por tipo de personal. Mantener en sincronía con
 * frontend/src/features/users/utils/regimenesLaborales.js.
 */
final class RegimenesLaborales
{
    /** @var array<string, array<string, string>> */
    public const POR_TIPO = [
        'docente' => [
            '30220' => 'Ley N° 30220 - Ley Universitaria',
        ],
        'administrativo' => [
            'CAS' => 'Decreto Legislativo N° 1057 (CAS)',
            '276' => 'Decreto Legislativo N° 276',
            '728' => 'Decreto Legislativo N° 728',
            '30057' => 'Ley N° 30057 (Ley del Servicio Civil)',
        ],
    ];

    /** @return list<string> */
    public static function codigos(mixed $tipoPersonal): array
    {
        return is_string($tipoPersonal) ? array_map('strval', array_keys(self::POR_TIPO[$tipoPersonal] ?? [])) : [];
    }
}
