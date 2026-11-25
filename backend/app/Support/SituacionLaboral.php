<?php

namespace App\Support;

use App\Models\Usuario;
use Illuminate\Database\Eloquent\Builder;

/**
 * Situación laboral de una persona en los listados, separada de su acceso al sistema (usuarios.activo, la cuenta).
 * Vínculo vigente con el mismo criterio de PuestoActual (no anulado y sin fecha de cese), y legajo activo como en la
 * apertura (AperturaLegajo). Un vínculo vigente manda: la persona no aparece sin vínculo por no tener legajo activo.
 * Pendiente del cliente: criterio único de vigencia (un vínculo vencido sin cese sigue vigente aquí).
 */
final class SituacionLaboral
{
    public const VINCULO_VIGENTE = 'vinculo_vigente';

    public const SIN_VINCULO_VIGENTE = 'sin_vinculo_vigente';

    public const SIN_LEGAJO = 'sin_legajo';

    /** Agrega a la consulta las dos marcas que necesita de() (sin cargar vínculos ni legajos). */
    public static function cargar(Builder $query): Builder
    {
        return $query->withExists([
            // Mismo criterio que PuestoActual::vigentes: sin cese o con cese de hoy en adelante (día de Lima).
            'vinculosLaborales as tiene_vinculo_vigente' => fn (Builder $vinculos) => $vinculos->etapaActual()->where('anulado', false)
                ->where(fn (Builder $q): Builder => $q->whereNull('fecha_cese')->orWhereDate('fecha_cese', '>=', VigenciaLaboral::hoy())),
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
