<?php

namespace App\Support;

use App\Models\VinculoLaboral;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/** Fechas efectivas incluidas, según el día institucional de Lima. */
final class VigenciaLaboral
{
    public static function hoy(): string
    {
        return now('America/Lima')->toDateString();
    }

    public static function consulta(Builder $query, ?string $fecha = null): Builder
    {
        $fecha ??= self::hoy();
        $tabla = $query->getModel()->getTable();
        $fin = "COALESCE((SELECT a.fecha_fin FROM vinculo_adendas a WHERE a.vinculo_laboral_id = {$tabla}.id AND a.anulado = ? ORDER BY a.fecha_inicio DESC, a.created_at DESC, a.id DESC LIMIT 1), {$tabla}.fecha_fin_prevista)";

        return $query->where("{$tabla}.anulado", false)->whereDate("{$tabla}.fecha_inicio", '<=', $fecha)
            ->where(fn (Builder $q) => $q->whereNull("{$tabla}.fecha_cese")->orWhereDate("{$tabla}.fecha_cese", '>=', $fecha))
            ->where(fn (Builder $q) => $q->whereRaw("{$fin} IS NULL", [false])->orWhereRaw("DATE({$fin}) >= ?", [false, $fecha]));
    }

    public static function fin(VinculoLaboral $vinculo): ?Carbon
    {
        $adenda = $vinculo->vinculoAdendas->where('anulado', false)->sort(function ($a, $b): int {
            return [$b->fecha_inicio?->format('Y-m-d'), $b->created_at?->format('Y-m-d H:i:s.u'), $b->id]
                <=> [$a->fecha_inicio?->format('Y-m-d'), $a->created_at?->format('Y-m-d H:i:s.u'), $a->id];
        })->first();

        return $adenda?->fecha_fin ?? $vinculo->fecha_fin_prevista;
    }

    /**
     * Situación de un vínculo a la fecha, solo cuando los datos la permiten afirmar. Un plazo vencido sin cese registrado
     * queda «no determinada»: no se afirma que siga ni que haya terminado. La comparten la ficha y las constancias.
     *
     * @return array{codigo: string, texto: string}
     */
    public static function situacion(VinculoLaboral $vinculo, ?string $fecha = null): array
    {
        $fecha ??= self::hoy();
        $fin = self::fin($vinculo);

        return match (true) {
            $vinculo->fecha_inicio->format('Y-m-d') > $fecha => ['codigo' => 'programado', 'texto' => 'Inicio previsto el '.$vinculo->fecha_inicio->format('d/m/Y')],
            $vinculo->fecha_cese !== null && $vinculo->fecha_cese->format('Y-m-d') < $fecha => ['codigo' => 'concluido', 'texto' => 'Concluido el '.$vinculo->fecha_cese->format('d/m/Y').' (cese registrado)'],
            self::vigente($vinculo, $fecha) => ['codigo' => 'vigente', 'texto' => 'Vigente a la fecha'],
            default => ['codigo' => 'no_determinada', 'texto' => 'No determinada: el plazo terminó el '.$fin?->format('d/m/Y').($vinculo->fecha_cese ? '' : ' y no hay cese registrado')],
        };
    }

    public static function vigente(VinculoLaboral $vinculo, ?string $fecha = null): bool
    {
        $fecha ??= self::hoy();
        $fin = self::fin($vinculo);

        return ! $vinculo->anulado && $vinculo->fecha_inicio !== null && $vinculo->fecha_inicio->format('Y-m-d') <= $fecha
            && ($vinculo->fecha_cese === null || $vinculo->fecha_cese->format('Y-m-d') >= $fecha)
            && ($fin === null || $fin->format('Y-m-d') >= $fecha);
    }
}
