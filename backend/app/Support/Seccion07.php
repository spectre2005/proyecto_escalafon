<?php

namespace App\Support;

/**
 * Sección 07 (Evaluación de desempeño, progresión en la carrera y desplazamiento) según el Anexo 01 de la Directiva
 * N° 001-2023-SERVIR-GDSRH; el Anexo 05 añade "Designación temporal de puesto o función" al encargo (7.3 e).
 *
 * Tipos de documento, subsección, literal de la directiva y qué registros de datos acredita cada uno. En 7.3 las
 * letras de la directiva (b a f) continúan las de 7.2; los códigos son propios.
 */
final class Seccion07
{
    public const SECCION = 7;

    /** desplazamientos.tipo (enum del esquema). */
    public const TIPOS_DESPLAZAMIENTO = ['designacion', 'rotacion', 'destaque', 'encargo_puesto', 'encargo_funciones', 'otro'];

    /** ratificaciones_docentes.tipo, .categoria_* y .resultado (enums del esquema). */
    public const TIPOS_RATIFICACION = ['nombramiento', 'ratificacion', 'promocion'];

    public const CATEGORIAS_DOCENTE = ['principal', 'asociado', 'auxiliar'];

    public const RESULTADOS_RATIFICACION = ['pendiente', 'ratificado', 'promovido', 'no_ratificado'];

    /**
     * Código del tipo de documento => [subsección, literal, formulario_datos, tipos de desplazamiento que acredita].
     * 7.2 a): progresiones_carrera para administrativos; para docentes el mismo documento acredita una ratificación o
     * promoción (FormulariosDatos::ALTERNATIVOS).
     * TODO pendiente de confirmar con el cliente: la directiva no menciona las ratificaciones docentes; se enlazan al
     * tipo "Resolución de progresión en la carrera".
     */
    public const TIPOS_DOCUMENTO = [
        'S07_71A_GESTION_RENDIMIENTO' => ['7.1', 'a', 'evaluaciones_desempeno', null],
        'S07_71B_ACTA_RETROALIMENTACION' => ['7.1', 'b', null, null],
        'S07_72A_RESOLUCION_PROGRESION' => ['7.2', 'a', 'progresiones_carrera', null],
        'S07_73B_DESIGNACION' => ['7.3', 'b', 'desplazamientos', ['designacion']],
        'S07_73C_ROTACION' => ['7.3', 'c', 'desplazamientos', ['rotacion']],
        'S07_73D_DESTAQUE' => ['7.3', 'd', 'desplazamientos', ['destaque']],
        'S07_73E_ENCARGO' => ['7.3', 'e', 'desplazamientos', ['encargo_puesto', 'encargo_funciones']],
        // f) "Otros" de la directiva = "otros documentos requeridos por la universidad" (Anexo 05).
        'S07_73F_OTROS' => ['7.3', 'f', 'desplazamientos', ['otro']],
    ];

    public const ACTA_RETROALIMENTACION = 'S07_71B_ACTA_RETROALIMENTACION';

    /**
     * Días de anticipación para avisar que vence el período de una ratificación docente.
     * TODO pendiente de confirmar con el cliente: cuántos días de anticipación.
     */
    public const DIAS_AVISO_RATIFICACION = 90;

    /**
     * Ciclo de evaluación cuyo registro se espera (aviso sin bloqueo): el del año en curso.
     * TODO pendiente de confirmar con el cliente: si el ciclo a revisar es el del año en curso o el del año anterior.
     */
    public static function cicloEnCurso(): int
    {
        return (int) today()->year;
    }

    public static function literal(string $codigo): ?string
    {
        return self::TIPOS_DOCUMENTO[$codigo][1] ?? null;
    }

    /** @return list<string>|null Tipos de desplazamiento que puede acreditar el documento; null si no es de 7.3. */
    public static function desplazamientos(?string $codigo): ?array
    {
        return $codigo === null ? null : (self::TIPOS_DOCUMENTO[$codigo][3] ?? null);
    }

    /** Si un documento de este tipo puede acreditar un desplazamiento de este tipo. Otros tipos no se restringen aquí. */
    public static function admiteDesplazamiento(?string $codigo, ?string $tipoDesplazamiento): bool
    {
        $permitidos = self::desplazamientos($codigo);

        return $permitidos === null || in_array($tipoDesplazamiento, $permitidos, true);
    }
}
