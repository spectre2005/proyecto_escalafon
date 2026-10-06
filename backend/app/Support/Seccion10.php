<?php

namespace App\Support;

/**
 * Sección 10 (Seguridad y Salud en el Trabajo y Bienestar Social) según el Anexo 01 de la Directiva
 * N° 001-2023-SERVIR-GDSRH: 10.1 SST (literales a a c) y 10.2 Bienestar social (literales a a d). El párrafo general
 * añade accidentes de trabajo, enfermedades ocupacionales u otros incidentes, y la afiliación a EPS o Seguro Social.
 *
 * Toda la sección es sensible en el proyecto (datos_sensibles.ver). Además, algunos tipos son datos de salud por su
 * naturaleza (Directiva, num. 6.2): accidentes, enfermedades ocupacionales, exámenes médicos y descansos médicos.
 */
final class Seccion10
{
    public const SECCION = 10;

    /**
     * Valores de registros_sst_bienestar.tipo que maneja la sección. Enum del esquema sin examen_medico_realizado.
     * TODO pendiente de confirmar con el cliente: examen_medico_realizado figura solo en el Anexo 05 ("solo se registra
     * el hecho, no los resultados"); no se registra hasta validarlo. "Otros incidentes" del párrafo general no tiene valor
     * en el esquema: no se registra hasta decidir si se agrega (requiere migración).
     */
    public const TIPOS_REGISTRO = ['entrega_reglamento_sst', 'entrenamiento_simulacro', 'capacitacion_sst',
        'accidente_trabajo', 'enfermedad_ocupacional', 'tramite_seguro', 'tramite_subsidio'];

    /** Tipos de registro que son datos de salud por su naturaleza. */
    public const TIPOS_SALUD = ['accidente_trabajo', 'enfermedad_ocupacional', 'examen_medico_realizado'];

    /** Solo los trámites tienen estado; los demás quedan en no_aplica (valor por defecto del esquema). */
    public const TRAMITES = ['tramite_seguro', 'tramite_subsidio'];

    /**
     * Enum del esquema (registros_sst_bienestar.estado_tramite).
     * TODO pendiente de confirmar con el cliente: si se gestiona el estado del trámite (hoy es opcional, sin reglas).
     */
    public const ESTADOS_TRAMITE = ['no_aplica', 'en_tramite', 'aprobado', 'denegado'];

    /** afiliaciones.tipo que maneja esta sección; sindicato es de la sección 09 y "otro" está pendiente de ubicar. */
    public const AFILIACIONES = ['eps', 'seguro_social'];

    /**
     * Código del tipo de documento => [subsección, literal, formulario_datos, valores de "tipo" del registro que acredita].
     * 10.2 c) Descansos médicos no tiene tipo propio: el registro y el PDF siguen en la sección 05 (licencias_permisos,
     * S05_DESCANSO_MEDICO) y la 10 solo los consulta.
     * TODO pendiente de confirmar con el cliente: dónde se archiva físicamente el descanso médico, si se registra una sola
     * vez y si la sección 10 solo lo consulta o también debe tener su propio documento.
     */
    public const TIPOS_DOCUMENTO = [
        'S10_1A_REGLAMENTO_SST' => ['10.1', 'a', 'registros_sst_bienestar', ['entrega_reglamento_sst']],
        'S10_1B_SIMULACROS' => ['10.1', 'b', 'registros_sst_bienestar', ['entrenamiento_simulacro']],
        'S10_1C_ASISTENCIA_CAPACITACION' => ['10.1', 'c', 'registros_sst_bienestar', ['capacitacion_sst']],
        'S10_1X_ACCIDENTES_ENFERMEDADES' => ['10.1', null, 'registros_sst_bienestar', ['accidente_trabajo', 'enfermedad_ocupacional']],
        'S10_2A_SEGUROS' => ['10.2', 'a', 'registros_sst_bienestar', ['tramite_seguro']],
        'S10_2B_SUBSIDIOS' => ['10.2', 'b', 'registros_sst_bienestar', ['tramite_subsidio']],
        'S10_2D_AFILIACION_EPS' => ['10.2', 'd', 'afiliaciones', self::AFILIACIONES],
        'S10_OTROS_UNIVERSIDAD' => [null, null, null, null],
    ];

    /** Tipos de documento que son datos de salud (además de la protección de toda la sección). */
    public const DOCUMENTOS_SALUD = ['S10_1X_ACCIDENTES_ENFERMEDADES'];

    public static function literal(string $codigo): ?string
    {
        return self::TIPOS_DOCUMENTO[$codigo][1] ?? null;
    }

    /** @return list<string>|null Valores de "tipo" del registro que el documento puede acreditar. */
    public static function registros(?string $codigo): ?array
    {
        return $codigo === null ? null : (self::TIPOS_DOCUMENTO[$codigo][3] ?? null);
    }

    /** Si un documento de este tipo puede acreditar un registro con ese "tipo". Los tipos de otras secciones no se restringen aquí. */
    public static function admite(?string $codigo, ?string $tipoRegistro): bool
    {
        if ($codigo === null || ! array_key_exists($codigo, self::TIPOS_DOCUMENTO)) {
            return true;
        }
        $permitidos = self::registros($codigo);

        return $permitidos !== null && in_array($tipoRegistro, $permitidos, true);
    }
}
