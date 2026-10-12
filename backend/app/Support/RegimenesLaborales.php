<?php

namespace App\Support;

use App\Models\RegimenNormativo;
use App\Models\VinculoLaboral;

/**
 * Régimen de una persona en dos datos distintos:
 * - Ley (régimen normativo): catálogo regimenes_normativos, con aplica_a = docente o administrativo. En la persona va en
 *   usuarios.regimen_normativo_id; usuarios.regimen_laboral guarda el código como respaldo de lectura.
 * - Tipo de vínculo: carrera, plazo determinado o designado, en vinculos_laborales.regimen_laboral (NULL = Sin definir,
 *   solo en vínculos anteriores). La ley no lo determina: un 276 puede ser carrera o designado.
 *
 * Mantener los tipos en sincronía con frontend/src/features/legajos/config/seccion02.js.
 */
final class RegimenesLaborales
{
    public const TIPOS_VINCULO = ['carrera' => 'Carrera', 'plazo_determinado' => 'Plazo determinado', 'designado' => 'Designado (confianza)'];

    /** Códigos de ley que se aceptan con otro nombre (el catálogo usa 1057 para el CAS). */
    public const ALIAS_LEY = ['CAS' => '1057'];

    public const SIN_DEFINIR = 'Sin definir: se define al registrar el vínculo en 2.2.';

    /** Vínculo anterior a la migración cuyo valor era una ley (p. ej. «276»): se confirma al corregirlo. */
    public const SIN_DEFINIR_VINCULO = 'Sin definir: confírmalo en «Corregir vínculo» de la sección 02.';

    /**
     * Sugerencia de tipo para un vínculo Sin definir, solo como pista: nunca se guarda sola, la confirma el administrador
     * al corregir el vínculo. Se apoya en los datos del puesto; si no alcanzan, no sugiere (la ley no determina el tipo).
     *
     * @return array{valor: ?string, motivo: string}|null null si el vínculo ya tiene tipo
     */
    public static function sugerencia(VinculoLaboral $vinculo): ?array
    {
        if ($vinculo->regimen_laboral !== null) {
            return null;
        }
        $docente = $vinculo->perfilDocente->first();
        $administrativo = $vinculo->perfilAdministrativo->first();

        return match (true) {
            $vinculo->tipo_personal === 'docente' && $docente?->condicion_docente === 'ordinario' => ['valor' => 'carrera', 'motivo' => 'Docente ordinario.'],
            $vinculo->tipo_personal === 'docente' && $docente?->condicion_docente === 'contratado' => ['valor' => 'plazo_determinado', 'motivo' => 'Docente contratado.'],
            // D.L. 276 admite carrera o designado: no se sugiere ninguno.
            $vinculo->regimenNormativo?->codigo === '276' => ['valor' => null, 'motivo' => 'Confirma si es carrera o designado.'],
            (bool) $administrativo?->es_cargo_confianza => ['valor' => 'designado', 'motivo' => 'Cargo de confianza.'],
            $vinculo->regimenNormativo?->codigo === '1057' => ['valor' => 'plazo_determinado', 'motivo' => 'Contrato CAS (D.L. 1057).'],
            default => ['valor' => null, 'motivo' => 'Los datos del puesto no alcanzan: confirma si es carrera, plazo determinado o designado.'],
        };
    }

    /** Ley por id o por código (con alias), o null si no existe. */
    public static function ley(?string $id, ?string $codigo = null): ?RegimenNormativo
    {
        if ($id) {
            return RegimenNormativo::query()->whereKey($id)->first();
        }

        return $codigo ? RegimenNormativo::query()->where('codigo', self::ALIAS_LEY[strtoupper($codigo)] ?? $codigo)->first() : null;
    }

    /** Motivo por el que la ley no vale para ese tipo de personal, o null si vale. */
    public static function error(?RegimenNormativo $ley, mixed $tipoPersonal): ?string
    {
        return match (true) {
            $ley === null => 'Indica la ley (régimen normativo) de la persona'.(self::codigos($tipoPersonal) ? ': '.implode(', ', self::codigos($tipoPersonal)) : '').'.',
            ! $ley->activo => "La ley {$ley->nombre} está inactiva en el catálogo.",
            is_string($tipoPersonal) && $ley->aplica_a !== $tipoPersonal => "La ley {$ley->nombre} corresponde al personal {$ley->aplica_a}, no al {$tipoPersonal}.",
            default => null,
        };
    }

    /** Código que se guarda como respaldo en usuarios.regimen_laboral (el alias, si lo tiene: CAS). */
    public static function codigoRespaldo(RegimenNormativo $ley): string
    {
        return (string) (array_search($ley->codigo, self::ALIAS_LEY, true) ?: $ley->codigo);
    }

    /** @return list<string> Códigos de ley que se aceptan para el tipo de personal (con su alias). */
    public static function codigos(mixed $tipoPersonal): array
    {
        if (! is_string($tipoPersonal)) {
            return [];
        }
        $codigos = RegimenNormativo::query()->where('aplica_a', $tipoPersonal)->where('activo', true)->orderBy('codigo')->pluck('codigo')->all();

        return array_values(array_map(fn (string $codigo): string => (string) (array_search($codigo, self::ALIAS_LEY, true) ?: $codigo), $codigos));
    }
}
