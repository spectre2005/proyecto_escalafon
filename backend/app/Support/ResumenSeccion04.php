<?php

namespace App\Support;

use Illuminate\Support\Collection;

final class ResumenSeccion04
{
    public const ITEMS = ['EXP_CERTIFICADO_TRABAJO' => ['a', 0], 'EXP_RESOLUCION_FUNCIONES' => ['b', 0], 'EXP_OTROS' => ['c', 0]];

    /** Cada certificado exigido corresponde a una experiencia declarada. Sin certificados se espera al menos uno. */
    public static function total(Collection $items): array
    {
        $exigidos = 0;
        $aceptados = 0;
        foreach ($items->where('exigido', true) as $item) {
            $vigentes = collect($item['documentos'])->filter(fn (array $doc): bool => ! $doc['subsanado'] && $doc['estado'] !== 'anulado');
            $exigidos += max(1, $vigentes->count());
            $aceptados += $vigentes->where('estado', 'aceptado')->count();
        }

        return [...ResumenSeccion03::total($items), 'exigidos' => $exigidos, 'aceptados' => $aceptados,
            'porcentaje' => $exigidos === 0 ? 100 : (int) round($aceptados / $exigidos * 100)];
    }
}
