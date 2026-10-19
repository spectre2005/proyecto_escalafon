<?php

namespace App\Support;

use App\Models\Usuario;
use Illuminate\Database\Eloquent\Builder;

/** Situación laboral por fechas efectivas incluidas, separada del acceso a la cuenta (usuarios.activo). */
final class SituacionLaboral
{
    public const VINCULO_VIGENTE = 'vinculo_vigente';

    public const SIN_VINCULO_VIGENTE = 'sin_vinculo_vigente';

    public const SIN_LEGAJO = 'sin_legajo';

    /** Agrega a la consulta las dos marcas que necesita de() (sin cargar vínculos ni legajos). */
    public static function cargar(Builder $query): Builder
    {
        return $query->withExists([
            'vinculosLaborales as tiene_vinculo_vigente' => fn (Builder $vinculos) => $vinculos->vigente(),
            'legajos as tiene_legajo_activo' => fn (Builder $legajos) => $legajos->where('estado_legajo', 'activo'),
        ]);
    }

    /** Situación de una persona cargada con cargar(), o null si la consulta no trajo las marcas. */
    public static function de(Usuario $persona): ?string
    {
        if (! array_key_exists('tiene_vinculo_vigente', $persona->getAttributes())) {
            return null;
        }

        return match (true) {
            (bool) $persona->tiene_vinculo_vigente => self::VINCULO_VIGENTE,
            (bool) $persona->tiene_legajo_activo => self::SIN_VINCULO_VIGENTE,
            default => self::SIN_LEGAJO,
        };
    }
}
