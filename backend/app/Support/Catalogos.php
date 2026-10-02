<?php

namespace App\Support;

/**
 * Listas cerradas del esquema (docs/escalafon_drawdb_55.sql, comentarios "enum").
 * Las etiquetas para mostrar viven en el frontend; aquí solo los valores válidos.
 */
final class Catalogos
{
    public const TIPOS_DOC_IDENTIDAD = ['dni', 'ce', 'pasaporte', 'otro'];

    public const ESTADOS_CIVILES = ['soltero', 'casado', 'conviviente', 'divorciado', 'viudo'];

    public const PARENTESCOS = ['conyuge', 'concubino', 'hijo', 'otro'];

    public const TIPOS_DECLARACION = [
        'antecedente_penal', 'antecedente_judicial', 'antecedente_policial', 'impedimentos_rnssc',
        'impedimentos_redjum', 'djbr', 'dj_intereses', 'dj_no_impedimentos', 'otra_dj',
    ];

    public const RESULTADOS_DECLARACION = ['presentada', 'sin_registros', 'con_registros', 'no_aplica'];

    public const PERSONAS_CERTIFICADO = ['servidor', 'familiar'];

    public static function in(array $valores): string
    {
        return 'in:'.implode(',', $valores);
    }
}
