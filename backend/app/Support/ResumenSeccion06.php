<?php

namespace App\Support;

use Illuminate\Support\Collection;

final class ResumenSeccion06
{
    public const ITEMS = [
        'COMP_RECONOCIMIENTO_TIEMPO' => ['a', 0], 'COMP_PAGO_COMPENSACION' => ['b', 0],
        'COMP_RETENCION_TRIBUTARIA' => ['c', 0], 'COMP_LIQUIDACION_BENEFICIOS' => ['d', 0], 'COMP_OTROS' => ['+', 0],
    ];

    /** Avance de revisión de documentos vigentes; no implica cumplimiento de obligaciones no definidas. */
    public static function total(Collection $items): array
    {
        $documentos = $items->flatMap(fn (array $item): array => $item['documentos'])
            ->filter(fn (array $doc): bool => ! $doc['subsanado'] && $doc['estado'] !== 'anulado');
        $aceptados = $documentos->where('estado', 'aceptado')->count();

        return ['registrados' => $documentos->count(), 'aceptados' => $aceptados,
            'pendientes' => $documentos->where('estado', 'pendiente')->count(), 'observados' => $documentos->where('estado', 'observado')->count(),
            'faltantes' => $items->where('estado', 'falta')->count(), 'por_determinar' => $items->filter(fn (array $item): bool => $item['exigido'] === null)->count(),
            'porcentaje' => $documentos->isEmpty() ? null : (int) round($aceptados / $documentos->count() * 100)];
    }
}
