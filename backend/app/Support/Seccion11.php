<?php

namespace App\Support;

/**
 * Sección 11 (Desvinculación) según el Anexo 01 de la Directiva N° 001-2023-SERVIR-GDSRH, literales a) a g), más la
 * aceptación de renuncia del Anexo 05 y los "otros documentos" de la universidad.
 *
 * Un registro de desvinculación por cada vínculo laboral que termina. La fecha de cese de referencia es la del vínculo
 * (sección 02): la desvinculación debe coincidir con ella. Todos los documentos de la sección que se enlazan quedan
 * agrupados por vínculo (documentos_usuario.vinculo_laboral_id); los del término (a, b, c, g y la aceptación de renuncia)
 * además acreditan el registro (desvinculaciones.documento_id, uno por registro).
 */
final class Seccion11
{
    public const SECCION = 11;

    /**
     * desvinculaciones.motivo_cese: enum del esquema más termino_carrera (literal b de la directiva), validado en la
     * aplicación; la columna es VARCHAR(25) sin restricción, no requiere migración.
     */
    public const MOTIVOS_CESE = ['renuncia', 'termino_designacion', 'termino_carrera', 'termino_contrato', 'no_renovacion',
        'destitucion', 'jubilacion', 'fallecimiento', 'otro'];

    /**
     * Código del tipo de documento => [bloque, literal, acredita el registro (principal), motivos de cese admitidos (null = todos)].
     * TODO pendiente de confirmar con el cliente:
     * - g) es del régimen del DL 1057: se exige que el vínculo sea de plazo determinado; el catálogo de regímenes
     *   normativos aún no permite identificar el DL 1057 por código.
     * - c) "memorando (o documento) de término de vínculo" admite cualquier motivo de cese (la directiva no lo precisa).
     * - La liquidación (f) se solapa con la sección 06 d): aquí no se copia ni se enlaza al documento de la 06.
     * - Destitución: la resolución disciplinaria es de la sección 08; aquí no se duplica.
     */
    public const TIPOS_DOCUMENTO = [
        'S11_A_TERMINO_DESIGNACION' => ['termino', 'a', true, ['termino_designacion']],
        'S11_B_TERMINO_CARRERA' => ['termino', 'b', true, ['termino_carrera']],
        'S11_C_TERMINO_VINCULO' => ['termino', 'c', true, null],
        'S11_G_NO_RENOVACION_CAS' => ['termino', 'g', true, ['no_renovacion']],
        'S11_X_ACEPTACION_RENUNCIA' => ['termino', null, true, ['renuncia']],
        'S11_D_INFORME_GESTION' => ['complementario', 'd', false, null],
        'S11_E_ENTREGA_CARGO' => ['complementario', 'e', false, null],
        'S11_F_LIQUIDACION' => ['complementario', 'f', false, null],
        'S11_OTROS_UNIVERSIDAD' => ['otros', null, false, null],
    ];

    /** Literal g): solo para vínculos de plazo determinado (DL 1057, CAS). */
    public const NO_RENOVACION_CAS = 'S11_G_NO_RENOVACION_CAS';

    public static function bloque(string $codigo): string
    {
        return self::TIPOS_DOCUMENTO[$codigo][0] ?? 'otros';
    }

    public static function literal(string $codigo): ?string
    {
        return self::TIPOS_DOCUMENTO[$codigo][1] ?? null;
    }

    /** ¿El documento acredita el registro de desvinculación (documento_id)? Los complementarios solo se agrupan por vínculo. */
    public static function principal(?string $codigo): bool
    {
        return (bool) (self::TIPOS_DOCUMENTO[$codigo ?? ''][2] ?? false);
    }

    /** @return list<string>|null */
    public static function motivos(?string $codigo): ?array
    {
        return self::TIPOS_DOCUMENTO[$codigo ?? ''][3] ?? null;
    }

    /** Por qué no se admite (con el tipo de vínculo Sin definir, dice dónde se define), o null si se admite. */
    public static function motivoRechazo(?string $codigo, ?string $motivo, ?string $regimenVinculo, string $general): ?string
    {
        if (self::admite($codigo, $motivo, $regimenVinculo)) {
            return null;
        }

        return $codigo === self::NO_RENOVACION_CAS && $regimenVinculo === null && self::admite($codigo, $motivo, 'plazo_determinado')
            ? 'Define el tipo de vínculo en la sección 02 (2.2: «Completar tipo» si el vínculo está cerrado).' : $general;
    }

    /** ¿Este tipo de documento puede enlazarse a una desvinculación con ese motivo y régimen del vínculo? */
    public static function admite(?string $codigo, ?string $motivo, ?string $regimenVinculo): bool
    {
        if ($codigo === null || ! array_key_exists($codigo, self::TIPOS_DOCUMENTO) || self::bloque($codigo) === 'otros') {
            return false;
        }
        $motivos = self::motivos($codigo);
        if ($motivos !== null && ! in_array($motivo, $motivos, true)) {
            return false;
        }

        return $codigo !== self::NO_RENOVACION_CAS || $regimenVinculo === 'plazo_determinado';
    }
}
