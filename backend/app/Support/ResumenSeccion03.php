<?php

namespace App\Support;

use Illuminate\Support\Collection;

final class ResumenSeccion03
{
    public const ITEMS = [
        'FORM_ESTUDIOS_BASICOS' => ['a', 0], 'FORM_TITULO_GRADO_SUPERIOR' => ['b', 0],
        'FORM_COLEGIATURA' => ['c', 0], 'FORM_POSTGRADO' => ['d', 1],
        'FORM_MAESTRIA_DOCTORADO' => ['e', 1], 'FORM_CAPACITACION' => ['f', 2],
        'FORM_CONGRESOS' => ['g', 2], 'FORM_OTROS' => ['h', 2],
    ];

    public static function estadoDocumento(string $estado): string
    {
        return match ($estado) {
            'aceptado', 'verificado' => 'aceptado',
            'observado', 'falso' => 'observado',
            'anulado' => 'anulado',
            default => 'pendiente',
        };
    }

    /** Los anulados y los subsanados quedan en el historial, sin afectar la completitud actual. */
    public static function estado(?bool $exigido, bool $opcional, Collection $documentos): string
    {
        $vigentes = $documentos->filter(fn (array $documento): bool => ! $documento['subsanado'] && $documento['estado'] !== 'anulado');
        if ($vigentes->isEmpty()) {
            return $exigido === true ? 'falta' : ($exigido === null ? 'por_determinar' : ($opcional ? 'opcional' : 'no_aplica'));
        }
        if ($vigentes->contains('estado', 'observado')) {
            return 'observado';
        }
        if ($vigentes->contains('estado', 'pendiente') || $vigentes->contains(fn (array $doc): bool => in_array($doc['estado'], ['aceptado', 'verificado'], true) && empty($doc['fecha_recepcion']))) {
            return 'pendiente';
        }

        return 'aceptado';
    }

    public static function total(Collection $items): array
    {
        $exigidos = $items->where('exigido', true);
        $aceptados = $exigidos->where('estado', 'aceptado')->count();

        return [
            'exigidos' => $exigidos->count(), 'aceptados' => $aceptados,
            'porcentaje' => $exigidos->isEmpty() ? 100 : (int) round($aceptados / $exigidos->count() * 100),
            'pendientes' => $items->where('estado', 'pendiente')->count(),
            'observados' => $items->where('estado', 'observado')->count(),
            'faltantes' => $items->where('estado', 'falta')->count(),
            'por_determinar' => $items->where('estado', 'por_determinar')->count(),
        ];
    }
}
