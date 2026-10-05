<?php

namespace App\Support;

/**
 * Sección 05 (Movimientos del personal) según el Anexo 01 de la Directiva N° 001-2023-SERVIR-GDSRH:
 * "documentación de la asistencia y permanencia… permisos (papeletas, memorándum u otros), suspensión,
 * vacaciones, descanso médico, licencias otorgadas, entre otros", literales a) a e).
 *
 * Listas cerradas de los registros (enums del esquema: licencias_permisos.tipo y goces_vacacionales.tipo),
 * bloque de la ficha al que pertenece cada tipo de documento y qué registros puede acreditar.
 */
final class Seccion05
{
    public const SECCION = 5;

    /** Literal e): licencias otorgadas (maternidad, paternidad, lactancia, adopción, sindical, etc. u otros). */
    public const LICENCIAS_OTORGADAS = ['licencia_maternidad', 'licencia_paternidad', 'lactancia', 'adopcion', 'sindical',
        'familiar_grave', 'comite_sst', 'asistencia_medica_familiar', 'bombero_voluntario', 'otra'];

    /** licencias_permisos.tipo: permisos, suspensión y descanso médico (párrafo general) y licencias (literal e). */
    public const TIPOS_LICENCIA = ['permiso', 'suspension', 'descanso_medico', ...self::LICENCIAS_OTORGADAS];

    /** goces_vacacionales.tipo: goce (literal b), adelanto o postergación (literal c). */
    public const TIPOS_GOCE = ['goce', 'adelanto', 'postergacion'];

    /**
     * Tipos de registro que son datos de salud (Directiva, num. 6.2: se leen solo con datos_sensibles.ver).
     * TODO pendiente de confirmar con el cliente: si otras licencias (p. ej. asistencia médica a un familiar)
     * también se tratan como datos de salud.
     */
    public const DATOS_SALUD = ['descanso_medico'];

    /**
     * Tipo de documento => [bloque de la ficha, registros que puede acreditar]. El bloque agrupa la vista
     * (no son subsecciones: la directiva no las define para la sección 05). null = el tipo no tiene registro de datos.
     */
    public const TIPOS_DOCUMENTO = [
        'S05_A_APRUEBA_LICENCIA_PERMISO' => ['licencias', ['permiso', ...self::LICENCIAS_OTORGADAS]],
        'S05_E_LICENCIA_OTORGADA' => ['licencias', self::LICENCIAS_OTORGADAS],
        'S05_SUSPENSION' => ['licencias', ['suspension']],
        'S05_DESCANSO_MEDICO' => ['licencias', ['descanso_medico']],
        'S05_B_GOCE_VACACIONAL' => ['vacaciones', ['goce']],
        'S05_C_ADELANTO_POSTERGACION' => ['vacaciones', ['adelanto', 'postergacion']],
        // TODO pendiente de confirmar con el cliente: si el rol de vacaciones es solo un documento o también datos estructurados.
        'S05_D_ROL_VACACIONES' => ['vacaciones', null],
        'S05_OTROS_UNIVERSIDAD' => ['otros', null],
    ];

    public const BLOQUES = ['licencias', 'vacaciones', 'otros'];

    public static function bloque(string $codigo): ?string
    {
        return self::TIPOS_DOCUMENTO[$codigo][0] ?? null;
    }

    /** @return list<string>|null */
    public static function registros(string $codigo): ?array
    {
        return self::TIPOS_DOCUMENTO[$codigo][1] ?? null;
    }

    /** Si un documento de este tipo puede acreditar un registro con este tipo. Los tipos de otras secciones no se restringen aquí. */
    public static function admite(?string $codigo, ?string $tipoRegistro): bool
    {
        if ($codigo === null || ! array_key_exists($codigo, self::TIPOS_DOCUMENTO)) {
            return true;
        }
        $permitidos = self::registros($codigo);

        return $permitidos !== null && in_array($tipoRegistro, $permitidos, true);
    }
}
