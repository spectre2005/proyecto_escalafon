<?php

namespace App\Support;

use App\Models\Usuario;
use App\Models\VinculoLaboral;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Puesto y unidad orgánica actuales de una persona. Parte de sus vínculos abiertos (no anulados y sin cese, o con cese
 * futuro) y muestra primero uno vigente según VigenciaLaboral (plazo y adendas incluidos; el de inicio más reciente). Si
 * ninguno lo está (plazo vencido sin cese o inicio futuro), muestra el abierto más reciente con su situación, sin
 * presentarlo como vigente. Sin vínculo abierto se muestran los que se registraron antes en la persona
 * (usuarios.nombre_puesto y unidad_organica_id), que se conservan como dato histórico; origen dice de dónde salen.
 */
final class PuestoActual
{
    /** Restricción de carga de los vínculos abiertos (para with() en listados). */
    public static function vigentes(HasMany $vinculos): HasMany
    {
        return $vinculos->where('anulado', false)
            ->where(fn (Builder $q): Builder => $q->whereNull('fecha_cese')->orWhereDate('fecha_cese', '>=', VigenciaLaboral::hoy()))
            ->with(['unidadOrganica:id,nombre', 'vinculoAdendas'])->orderByDesc('fecha_inicio')->orderByDesc('id');
    }

    /** @return array{nombre_puesto: ?string, unidad_organica: ?string, origen: ?string, vinculos_vigentes: int, situacion: ?array{codigo: string, texto: string}} */
    public static function de(Usuario $persona): array
    {
        $abiertos = $persona->relationLoaded('vinculosLaborales') ? $persona->vinculosLaborales
            : self::vigentes($persona->vinculosLaborales())->get();
        $vigentes = $abiertos->filter(fn (VinculoLaboral $vinculo): bool => VigenciaLaboral::vigente($vinculo));
        $actual = $vigentes->first() ?? $abiertos->first();
        if ($actual instanceof VinculoLaboral) {
            return ['nombre_puesto' => $actual->nombre_puesto, 'unidad_organica' => $actual->unidadOrganica?->nombre, 'origen' => 'vinculo',
                'vinculos_vigentes' => $vigentes->count(), 'situacion' => VigenciaLaboral::situacion($actual)];
        }
        $unidad = $persona->unidad_organica_id ? $persona->unidadOrganica?->nombre : null;

        return ['nombre_puesto' => $persona->nombre_puesto, 'unidad_organica' => $unidad,
            'origen' => $persona->nombre_puesto || $unidad ? 'persona' : null, 'vinculos_vigentes' => 0, 'situacion' => null];
    }
}
