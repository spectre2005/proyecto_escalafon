<?php

namespace App\Support;

use App\Models\CertificadoDiscapacidad;
use App\Models\DeclaracionVerificacion;
use App\Models\Familiar;
use App\Models\TipoDocumento;
use Illuminate\Database\Eloquent\Model;

/**
 * Correspondencia entre los tipos de documento de la sección 01 (Seccion01CatalogoSeeder) y los registros
 * de datos que acreditan: qué tipo de declaración (c, d, e, f, h, l y los dos del Anexo 05) y qué parentesco
 * (i, j). Un documento solo se enlaza a un registro compatible con su tipo.
 */
final class Seccion01
{
    /** Código del tipo de documento => tipos de declaraciones_verificaciones que acredita. */
    public const DECLARACIONES = [
        'S01_C_ANTECEDENTES_PENALES' => ['antecedente_penal'],
        'S01_D_ANTECEDENTES_POLICIALES' => ['antecedente_policial'],
        'S01_E_DJ_BIENES_RENTAS' => ['djbr'],
        'S01_F_DJ_NO_IMPEDIMENTOS_31419' => ['dj_no_impedimentos'],
        'S01_H_IMPEDIMENTOS_INHABILITACIONES' => ['impedimentos_rnssc', 'impedimentos_redjum'],
        'S01_L_OTRAS_DJ' => ['otra_dj'],
        'S01_X_ANTECEDENTES_JUDICIALES' => ['antecedente_judicial'],
        'S01_X_DJ_INTERESES' => ['dj_intereses'],
    ];

    /** Código del tipo de documento => parentescos del familiar que acredita. */
    public const PARENTESCOS = [
        'S01_I_DNI_CONYUGE_CONCUBINO' => ['conyuge', 'concubino'],
        'S01_J_DNI_HIJOS_MENORES' => ['hijo'],
    ];

    public const CONYUGES = ['conyuge', 'concubino'];

    /** g) se exige si hay cónyuge o concubino/a registrado, sin enlace a familiares (el DNI del cónyuge es i). */
    public const PARTIDA_MATRIMONIO = 'S01_G_PARTIDA_MATRIMONIO_CONCUBINATO';

    public const DNI_CONYUGE = 'S01_I_DNI_CONYUGE_CONCUBINO';

    public const DNI_HIJOS = 'S01_J_DNI_HIJOS_MENORES';

    /** Literal del Anexo 01 (a a l) a partir del código; los del Anexo 05 y el de la universidad no tienen. */
    public static function literal(string $codigo): ?string
    {
        return preg_match('/^S01_([A-L])_/', $codigo, $match) ? strtolower($match[1]) : null;
    }

    /** TODO pendiente de decidir si manda el Anexo 01 o el 05: estos tipos solo figuran en el Anexo 05. */
    public static function soloAnexo05(string $codigo): bool
    {
        return str_starts_with($codigo, 'S01_X_');
    }

    /** ¿El registro de datos es del tipo que acredita el documento? Los demás formularios no se restringen. */
    public static function compatible(TipoDocumento $tipo, Model $registro): bool
    {
        return match (true) {
            $registro instanceof DeclaracionVerificacion => in_array($registro->tipo, self::DECLARACIONES[$tipo->codigo] ?? [], true),
            $registro instanceof Familiar => in_array($registro->parentesco, self::PARENTESCOS[$tipo->codigo] ?? [], true),
            $registro instanceof CertificadoDiscapacidad => $tipo->formulario_datos === 'certificados_discapacidad',
            default => true,
        };
    }
}
