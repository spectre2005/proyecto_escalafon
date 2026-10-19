<?php

namespace App\Support;

use App\Models\Legajo;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class CierreLegajo
{
    /** Los vínculos que terminan hoy siguen vigentes hasta el cierre del día de Lima. */
    public static function actualizar(string $usuario, ?Request $request = null): bool
    {
        return DB::transaction(function () use ($usuario, $request): bool {
            Usuario::query()->whereKey($usuario)->lockForUpdate()->firstOrFail();
            $legajo = Legajo::query()->where('usuario_id', $usuario)->where('estado_legajo', 'activo')->lockForUpdate()->first();
            if (! $legajo || VinculoLaboral::query()->where('usuario_id', $usuario)->vigente()->exists()) {
                return false;
            }
            $vinculos = VinculoLaboral::query()->where('usuario_id', $usuario)->where('anulado', false)->whereDate('fecha_inicio', '<=', VigenciaLaboral::hoy())->with('vinculoAdendas')->get();
            if ($vinculos->isEmpty()) {
                return false;
            }
            $fechas = $vinculos->map(function ($v): ?string {
                $fin = VigenciaLaboral::fin($v)?->format('Y-m-d');
                $cese = $v->fecha_cese?->format('Y-m-d');

                return $fin && $cese ? min($fin, $cese) : ($cese ?? $fin);
            });
            if ($fechas->contains(null) || $fechas->max() >= VigenciaLaboral::hoy()) {
                return false;
            }
            // Un legajo nuevo sin vínculo asociado todavía no se vuelve a cerrar por ceses de un legajo pasivo.
            $pasivoAnterior = Legajo::query()->where('usuario_id', $usuario)->where('estado_legajo', 'pasivo')->whereKeyNot($legajo->id)->max('fecha_pasivo');
            if ($pasivoAnterior && $fechas->max() < substr((string) $pasivoAnterior, 0, 10)) {
                return false;
            }
            $antes = $legajo->getAttributes();
            $legajo->update(['estado_legajo' => 'pasivo', 'fecha_pasivo' => VigenciaLaboral::hoy()]);
            AuditoriaInstitucional::registrar('legajos', $legajo->id, 'legajo.pase_pasivo_automatico', $request, $usuario, $antes, $legajo->getAttributes());

            return true;
        });
    }
}
