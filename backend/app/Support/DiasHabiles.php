<?php

namespace App\Support;

use App\Models\Feriado;
use Illuminate\Support\Carbon;

/**
 * Días hábiles de la Directiva: sin sábados, domingos ni los feriados registrados por la institución (tabla feriados).
 * Los plazos se cuentan desde el día siguiente al requerimiento (art. 4.3).
 */
final class DiasHabiles
{
    /** Art. 4.3: documentación complementaria, plazo no mayor a 5 días hábiles. */
    public const PLAZO_DOCUMENTOS = 5;

    /** Art. 4.3: prórroga adicional de hasta 5 días hábiles. */
    public const PRORROGA_DOCUMENTOS = 5;

    /** Art. 5.1: actualización, el plazo que indique la ORH o, en su defecto, hasta 30 días hábiles. */
    public const PLAZO_ACTUALIZACION = 30;

    /** Fecha que resulta de contar $dias días hábiles desde el día siguiente a $desde (hoy en Lima si no se indica). */
    public static function sumar(int $dias, Carbon|string|null $desde = null): Carbon
    {
        $fecha = ($desde === null ? now('America/Lima') : Carbon::parse($desde, 'America/Lima'))->startOfDay();
        $feriados = Feriado::query()->whereBetween('fecha', [$fecha->toDateString(), $fecha->copy()->addDays($dias * 3 + 30)->toDateString()])
            ->pluck('fecha')->map(fn ($f): string => Carbon::parse($f)->toDateString())->flip();
        $contados = 0;
        while ($contados < $dias) {
            $fecha->addDay();
            if (! $fecha->isWeekend() && ! $feriados->has($fecha->toDateString())) {
                $contados++;
            }
        }

        return $fecha;
    }

    /** Días hábiles entre el día siguiente a $desde y $hasta, incluido (0 si $hasta no es posterior). */
    public static function entre(Carbon|string $desde, Carbon|string $hasta): int
    {
        $inicio = Carbon::parse($desde, 'America/Lima')->startOfDay();
        $fin = Carbon::parse($hasta, 'America/Lima')->startOfDay();
        if ($fin->lte($inicio)) {
            return 0;
        }
        $feriados = Feriado::query()->whereBetween('fecha', [$inicio->toDateString(), $fin->toDateString()])
            ->pluck('fecha')->map(fn ($f): string => Carbon::parse($f)->toDateString())->flip();
        $dias = 0;
        for ($fecha = $inicio->copy()->addDay(); $fecha->lte($fin); $fecha->addDay()) {
            if (! $fecha->isWeekend() && ! $feriados->has($fecha->toDateString())) {
                $dias++;
            }
        }

        return $dias;
    }
}
