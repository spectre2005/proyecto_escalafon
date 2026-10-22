<?php

namespace App\Support\Identidad;

use App\Models\Ubigeo;
use Illuminate\Support\Str;

/**
 * Empareja la ubicación que informa el proveedor de identidad con el catálogo de ubicaciones de la institución. Compara
 * región, provincia y distrito sin distinguir mayúsculas, tildes ni espacios repetidos, y solo acepta una coincidencia
 * única de los tres niveles: nunca aproxima ni inventa códigos. Sin coincidencia o con varias, la ubicación se elige a
 * mano en el formulario.
 */
final class UbicacionCatalogo
{
    public const ASIGNADA = 'asignada';

    public const SIN_DATO = 'sin_dato';

    public const SIN_COINCIDENCIA = 'sin_coincidencia';

    public const AMBIGUA = 'ambigua';

    /**
     * @param  array{departamento: string, provincia: string, distrito: string}|null  $ubicacion
     * @return array{estado: string, ubicacion: array{id: string, region: string, provincia: string, distrito: string}|null}
     */
    public static function emparejar(?array $ubicacion): array
    {
        if ($ubicacion === null) {
            return ['estado' => self::SIN_DATO, 'ubicacion' => null];
        }
        $buscada = [self::normalizar($ubicacion['departamento']), self::normalizar($ubicacion['provincia']), self::normalizar($ubicacion['distrito'])];
        $coincidencias = Ubigeo::query()->get(['id', 'region', 'provincia', 'distrito'])
            ->filter(fn (Ubigeo $fila): bool => [self::normalizar($fila->region), self::normalizar($fila->provincia), self::normalizar($fila->distrito)] === $buscada)
            ->values();

        return match ($coincidencias->count()) {
            1 => ['estado' => self::ASIGNADA, 'ubicacion' => $coincidencias->first()->only(['id', 'region', 'provincia', 'distrito'])],
            0 => ['estado' => self::SIN_COINCIDENCIA, 'ubicacion' => null],
            default => ['estado' => self::AMBIGUA, 'ubicacion' => null],
        };
    }

    public static function normalizar(string $valor): string
    {
        return mb_strtolower(Str::ascii(Str::squish($valor)));
    }
}
