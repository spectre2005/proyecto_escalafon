<?php

namespace App\Support;

use App\Models\VinculoLaboral;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class VencimientoVinculo
{
    public static function fecha(VinculoLaboral $vinculo): ?CarbonInterface
    {
        return $vinculo->vinculoAdendas->last()?->fecha_fin ?? $vinculo->fecha_fin_prevista;
    }

    public static function ultimaAdenda(): Builder
    {
        return DB::table('vinculo_adendas')->select('fecha_fin')->whereColumn('vinculo_laboral_id', 'v.id')
            ->where('anulado', false)->orderByDesc('fecha_inicio')->orderByDesc('created_at')->orderByDesc('id')->limit(1);
    }
}
