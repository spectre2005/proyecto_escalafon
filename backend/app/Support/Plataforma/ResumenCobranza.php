<?php

namespace App\Support\Plataforma;

use App\Models\Plataforma\Institucion;
use App\Models\Plataforma\PeriodoServicio;
use Illuminate\Support\Carbon;

/**
 * Resumen del tiempo de uso pagado para el panel de plataforma.
 *
 * Vencimientos: salen de Institucion::servicio(), que da un solo estado por institución, así que ninguna aparece en dos
 * grupos. Por vencer = vigente con 0 a DIAS días restantes; en gracia = en_gracia; vencidas = vencido. Las que no
 * tienen ningún pago (sin_periodos) no se controlan y solo se cuentan.
 *
 * Ingresos: suma de los montos de los pagos no anulados, por la fecha en que se registraron en el panel (created_at),
 * en PLATAFORMA_ZONA_HORARIA (decisión del 18/10/2026). Los pagos sin monto no se suman: se cuentan aparte. La moneda
 * es la del formulario de pagos (S/), pendiente de confirmar con el cliente.
 */
final class ResumenCobranza
{
    public const DIAS = 30;

    public const MONEDA = 'S/';

    /** @return array{por_vencer: list<array<string, mixed>>, en_gracia: list<array<string, mixed>>, vencidas: list<array<string, mixed>>, sin_control: int, dias: int} */
    public static function vencimientos(): array
    {
        $hoy = self::hoy();
        $grupos = ['por_vencer' => [], 'en_gracia' => [], 'vencidas' => []];
        $sinControl = 0;
        foreach (Institucion::query()->with('periodos')->orderBy('nombre')->orderBy('id')->get() as $institucion) {
            $servicio = $institucion->servicio();
            $base = ['id' => $institucion->id, 'nombre' => $institucion->nombre, 'estado' => $institucion->estado, 'vence_el' => $servicio['vence_el']];
            if ($servicio['estado'] === 'vigente' && $servicio['dias_restantes'] <= self::DIAS) {
                $grupos['por_vencer'][] = [...$base, 'dias_restantes' => $servicio['dias_restantes']];
            } elseif ($servicio['estado'] === 'en_gracia') {
                // Con dias_gracia = 0 no hay gracia: en_gracia siempre trae gracia_hasta.
                $grupos['en_gracia'][] = [...$base, 'gracia_hasta' => $servicio['gracia_hasta'],
                    'dias_gracia_restantes' => (int) $hoy->diffInDays(Carbon::parse((string) $servicio['gracia_hasta'], $hoy->tz))];
            } elseif ($servicio['estado'] === 'vencido') {
                // Sin vence_el: su único pago aún no empieza (proximo_desde).
                $grupos['vencidas'][] = [...$base, 'proximo_desde' => $servicio['proximo_desde'],
                    'dias_vencida' => $servicio['vence_el'] ? (int) Carbon::parse($servicio['vence_el'], $hoy->tz)->diffInDays($hoy) : null];
            } elseif ($servicio['estado'] === 'sin_periodos') {
                $sinControl++;
            }
        }
        usort($grupos['por_vencer'], fn (array $a, array $b): int => $a['dias_restantes'] <=> $b['dias_restantes']);
        usort($grupos['en_gracia'], fn (array $a, array $b): int => $a['dias_gracia_restantes'] <=> $b['dias_gracia_restantes']);
        usort($grupos['vencidas'], fn (array $a, array $b): int => (string) $a['vence_el'] <=> (string) $b['vence_el']);

        return [...$grupos, 'sin_control' => $sinControl, 'dias' => self::DIAS];
    }

    /** @return array{mes: array<string, mixed>, anio: array<string, mixed>, moneda: string} */
    public static function ingresos(): array
    {
        $hoy = self::hoy();

        return ['mes' => self::sumar($hoy->copy()->startOfMonth(), $hoy->copy()->endOfMonth()),
            'anio' => self::sumar($hoy->copy()->startOfYear(), $hoy->copy()->endOfYear()), 'moneda' => self::MONEDA];
    }

    /** Pagos no anulados registrados entre $desde y $hasta (hora local, ambos incluidos). */
    private static function sumar(Carbon $desde, Carbon $hasta): array
    {
        $pagos = PeriodoServicio::query()->where('anulado', false)
            ->whereBetween('created_at', [$desde->copy()->utc(), $hasta->copy()->utc()]);
        $total = (clone $pagos)->whereNotNull('monto')->sum('monto');

        return ['total' => number_format((float) $total, 2, '.', ''), 'pagos' => (clone $pagos)->count(),
            'sin_monto' => (clone $pagos)->whereNull('monto')->count(), 'desde' => $desde->toDateString(), 'hasta' => $hasta->toDateString()];
    }

    private static function hoy(): Carbon
    {
        return Carbon::now((string) config('plataforma.zona_horaria'))->startOfDay();
    }
}
