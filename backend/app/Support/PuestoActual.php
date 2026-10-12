<?php

namespace App\Support;

use App\Models\Usuario;
use App\Models\VinculoLaboral;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Puesto y unidad orgánica actuales de una persona: los de sus vínculos vigentes (no anulados y sin fecha de cese; el de
 * inicio más reciente primero). Sin vínculo vigente se muestran los que se registraron antes en la persona
 * (usuarios.nombre_puesto y unidad_organica_id), que se conservan como dato histórico; origen dice de dónde salen.
 */
final class PuestoActual
{
    /** Restricción de carga de los vínculos vigentes (para with() en listados). */
    public static function vigentes(HasMany $vinculos): HasMany
    {
        return $vinculos->where('anulado', false)->whereNull('fecha_cese')->with('unidadOrganica:id,nombre')
            ->orderByDesc('fecha_inicio')->orderByDesc('id');
    }

    /** @return array{nombre_puesto: ?string, unidad_organica: ?string, origen: ?string, vinculos_vigentes: int} */
    public static function de(Usuario $persona): array
    {
        $vigentes = $persona->relationLoaded('vinculosLaborales') ? $persona->vinculosLaborales
            : self::vigentes($persona->vinculosLaborales())->get();
        $actual = $vigentes->first();
        if ($actual instanceof VinculoLaboral) {
            return ['nombre_puesto' => $actual->nombre_puesto, 'unidad_organica' => $actual->unidadOrganica?->nombre, 'origen' => 'vinculo', 'vinculos_vigentes' => $vigentes->count()];
        }
        $unidad = $persona->unidad_organica_id ? $persona->unidadOrganica?->nombre : null;

        return ['nombre_puesto' => $persona->nombre_puesto, 'unidad_organica' => $unidad,
            'origen' => $persona->nombre_puesto || $unidad ? 'persona' : null, 'vinculos_vigentes' => 0];
    }
}
