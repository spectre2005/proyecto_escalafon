<?php

namespace App\Support;

/**
 * Sección 09 (Relaciones laborales individuales y colectivas) según el Anexo 01 de la Directiva N° 001-2023-SERVIR-GDSRH:
 * "documentación relacionada a la resolución de controversias en la entidad, ante el Tribunal del Servicio Civil y Poder
 * Judicial, entre otros; así como a la afiliación de el/la servidor/a al sindicato", literales a) a c).
 *
 * Sección sensible (Directiva, definición "e": la afiliación sindical es dato sensible): todo exige datos_sensibles.ver.
 * Sin subsecciones: la directiva no las define; la ficha agrupa la vista en bloques.
 */
final class Seccion09
{
    public const SECCION = 9;

    /** Enums del esquema (controversias.tipo, .instancia, .estado). No se agregan valores. */
    public const TIPOS_CONTROVERSIA = ['individual', 'colectiva'];

    public const INSTANCIAS = ['entidad', 'tribunal_servicio_civil', 'poder_judicial', 'otra'];

    public const ESTADOS_CONTROVERSIA = ['en_tramite', 'resuelta', 'archivada'];

    /**
     * Único tipo de afiliaciones que maneja esta sección (literal c).
     * TODO pendiente de confirmar con el cliente: dónde van las afiliaciones eps, seguro_social y otro de la misma tabla;
     * la sección 09 no las muestra, no las registra ni las anula.
     */
    public const AFILIACION = 'sindicato';

    /**
     * Código del tipo de documento => [bloque de la ficha, literal, formulario_datos, valores de "tipo" del registro que acredita].
     * TODO pendiente de confirmar con el cliente:
     * - Una controversia puede tener varios documentos (demanda, resoluciones, apelaciones); el registro guarda uno solo
     *   (documento_id). Los demás se suben como documentos adicionales del mismo tipo. Puede requerir una mejora del esquema.
     * - Una controversia colectiva se registra solo en el legajo donde se carga; no se copia a otras personas.
     * - Recursos contra sanciones disciplinarias: los contempla la sección 08 (8.2 b), no esta sección.
     */
    public const TIPOS_DOCUMENTO = [
        'S09_A_CONTROVERSIAS_INDIVIDUALES' => ['individuales', 'a', 'controversias', ['individual']],
        'S09_B_CONTROVERSIAS_COLECTIVAS' => ['colectivas', 'b', 'controversias', ['colectiva']],
        'S09_C_AFILIACION_SINDICATO' => ['afiliacion', 'c', 'afiliaciones', [self::AFILIACION]],
        'S09_OTROS_UNIVERSIDAD' => ['otros', null, null, null],
    ];

    public const BLOQUES = ['individuales', 'colectivas', 'afiliacion', 'otros'];

    public static function bloque(string $codigo): string
    {
        return self::TIPOS_DOCUMENTO[$codigo][0] ?? 'otros';
    }

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
