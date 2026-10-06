<?php

namespace App\Support;

use Illuminate\Support\Collection;

final class ResumenSeccion08
{
    public const ITEMS = [
        'REC_CARTA_FELICITACION' => ['a', 'reconocimientos'],
        'REC_LABOR_DOCENTE' => ['b', 'reconocimientos'],
        'REC_TIEMPO_SERVICIOS' => ['c', 'reconocimientos'],
        'SAN_RESOLUCION_SANCION' => ['a', 'sanciones'],
        'SAN_RESOLUCION_RECURSO' => ['b', 'sanciones'],
    ];

    public static function estado(Collection $documentos, bool $noAplica = false): string
    {
        if ($noAplica) {
            return 'no_aplica';
        }
        $vigentes = $documentos->reject(fn (array $documento): bool => $documento['subsanado'] || $documento['estado'] === 'anulado');
        if ($vigentes->isEmpty()) {
            return 'opcional';
        }
        if ($vigentes->contains('estado', 'observado')) {
            return 'observado';
        }
        if ($vigentes->contains('estado', 'pendiente')) {
            return 'pendiente';
        }

        return 'aceptado';
    }

    /** @param Collection<int, mixed> $recursos */
    public static function estadoSancion(Collection $recursos): ?string
    {
        $resultados = $recursos->pluck('resultado')->unique()->values();
        if ($resultados->contains('FUNDADO') && $resultados->count() > 1) {
            return null;
        }

        return $resultados->contains('FUNDADO') ? 'dejada_sin_efecto' : 'vigente';
    }

    /** @param Collection<int, array<string, mixed>> $items */
    public static function resumen(Collection $items, bool $puedeVerSanciones): array
    {
        $docs = $items->flatMap(fn (array $item): array => $item['documentos'])
            ->reject(fn (array $documento): bool => $documento['subsanado'] || $documento['estado'] === 'anulado');
        $reconocimientos = $docs->where('grupo', 'reconocimientos');
        $sanciones = $docs->where('grupo', 'sanciones');

        return [
            'reconocimientos' => $reconocimientos->count(),
            'sanciones' => $puedeVerSanciones ? $sanciones->where('tipo_documento_codigo', 'SAN_RESOLUCION_SANCION')->count() : null,
            'vigentes' => $puedeVerSanciones ? $sanciones->where('estado_sancion', 'vigente')->count() : null,
            'dejadas_sin_efecto' => $puedeVerSanciones ? $sanciones->where('estado_sancion', 'dejada_sin_efecto')->count() : null,
            'pendientes' => $docs->where('estado', 'pendiente')->count(),
            'observados' => $docs->where('estado', 'observado')->count(),
        ];
    }
}
