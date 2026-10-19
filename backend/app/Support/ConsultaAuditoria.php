<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

final class ConsultaAuditoria
{
    public static function filtros(Request $request): array
    {
        $filtros = $request->validate(['desde' => 'nullable|date_format:Y-m-d', 'hasta' => 'nullable|date_format:Y-m-d',
            'accion' => 'nullable|string|max:120', 'entidad' => 'nullable|string|max:100', 'actor' => 'nullable|string|max:120',
            'institucion' => 'nullable|string|max:150', 'institucion_id' => 'nullable|uuid', 'usuario_id' => 'nullable|uuid', 'legajo_id' => 'nullable|uuid', 'page' => 'sometimes|integer|min:1']);
        $hasta = $filtros['hasta'] ?? CarbonImmutable::now('America/Lima')->toDateString();
        $desde = $filtros['desde'] ?? CarbonImmutable::parse($hasta, 'America/Lima')->subDays(30)->toDateString();
        if ($desde > $hasta) {
            throw ValidationException::withMessages(['hasta' => 'La fecha final no puede ser anterior a la inicial.']);
        }

        return [...$filtros, 'desde' => $desde, 'hasta' => $hasta];
    }

    public static function fechas(Builder $query, string $columna, array $filtros): Builder
    {
        $hoy = CarbonImmutable::now('America/Lima')->startOfDay();
        $desde = CarbonImmutable::parse($filtros['desde'] ?? $hoy->subDays(30)->toDateString(), 'America/Lima')->startOfDay();
        $hasta = CarbonImmutable::parse($filtros['hasta'] ?? $hoy->toDateString(), 'America/Lima')->startOfDay()->addDay();

        return $query->where($columna, '>=', $desde->utc())->where($columna, '<', $hasta->utc());
    }

    public static function redactar(mixed $datos): mixed
    {
        if (is_string($datos)) {
            $datos = json_decode($datos, true);
        }
        if (! is_array($datos)) {
            return null;
        }
        foreach ($datos as $clave => $valor) {
            if (preg_match('/password|clave|token|secret|cookie|authorization|credential/i', (string) $clave)) {
                $datos[$clave] = '[Omitido]';
            } elseif (is_array($valor)) {
                $datos[$clave] = self::redactar($valor);
            }
        }

        return $datos;
    }

    public static function csv(Builder $query, Closure $fila, array $cabecera, string $nombre): BinaryFileResponse
    {
        $carpeta = storage_path('framework/auditoria');
        File::ensureDirectoryExists($carpeta, 0700);
        $ruta = tempnam($carpeta, 'consulta-');
        if ($ruta === false) {
            throw new RuntimeException('No se pudo preparar la exportación de auditoría.');
        }
        chmod($ruta, 0600);
        $salida = fopen($ruta, 'wb');
        if ($salida === false) {
            File::delete($ruta);
            throw new RuntimeException('No se pudo abrir la exportación de auditoría.');
        }
        try {
            fwrite($salida, "\xEF\xBB\xBF");
            fputcsv($salida, $cabecera, ';', '"', '');
            foreach ($query->reorder()->lazyByIdDesc(200, 'id') as $registro) {
                $valores = array_map(static function ($valor): string {
                    $texto = (string) ($valor ?? '');

                    return preg_match('/^\s*[=+@-]/u', $texto) ? "'".$texto : $texto;
                }, $fila($registro));
                if (fputcsv($salida, $valores, ';', '"', '') === false) {
                    throw new RuntimeException('No se pudo completar la exportación de auditoría.');
                }
            }
        } catch (Throwable $error) {
            fclose($salida);
            File::delete($ruta);
            throw $error;
        }
        fclose($salida);
        clearstatcache(true, $ruta);
        app()->terminating(static fn () => File::delete($ruta));

        return response()->download($ruta, $nombre, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store'])->deleteFileAfterSend(true);
    }
}
