<?php

namespace App\Support;

use App\Models\ExperienciaLaboral;
use App\Models\ParametroSistema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** Días inclusivos y unión de intervalos. No contiene mínimos de requisitos. */
final class TiempoExperiencia
{
    public static function configuracion(): array
    {
        $valores = ParametroSistema::query()->whereIn('clave', ['exp_incluir_pendientes', 'exp_dias_por_mes', 'exp_dias_por_anio', 'exp_hueco_largo_dias'])->get();
        foreach ($valores->groupBy('clave') as $filas) {
            if ($filas->count() > 1) {
                throw ValidationException::withMessages(['parametros' => 'Hay parámetros de experiencia duplicados. Administración debe revisar su configuración.']);
            }
        }
        $parametros = $valores->pluck('valor', 'clave');
        $config = ['incluir_pendientes' => ($parametros['exp_incluir_pendientes'] ?? '0') === '1'];
        if (! in_array($parametros['exp_incluir_pendientes'] ?? '0', ['0', '1'], true)) {
            throw ValidationException::withMessages(['parametros' => 'El parámetro de inclusión de pendientes debe ser 0 o 1.']);
        }
        foreach (['dias_por_mes', 'dias_por_anio', 'hueco_largo_dias'] as $clave) {
            $valor = $parametros['exp_'.$clave] ?? config('legajo.exp_'.$clave);
            if (! ctype_digit((string) $valor) || (int) $valor < 1) {
                throw ValidationException::withMessages(['parametros' => 'Los parámetros de tiempo de experiencia deben ser enteros positivos.']);
            }
            $config[$clave] = (int) $valor;
        }
        if ($config['dias_por_anio'] < $config['dias_por_mes']) {
            throw ValidationException::withMessages(['parametros' => 'Los días por año no pueden ser menores que los días por mes.']);
        }

        return $config;
    }

    /** Experiencia de la persona en todos sus legajos, también los anteriores. */
    public static function paraPersona(string $usuario, ?string $corte = null, bool $permitirSensibles = true): array
    {
        $config = self::configuracion();
        $periodos = ExperienciaLaboral::query()->where('usuario_id', $usuario)->where('anulado', false)
            ->whereHas('documento', fn ($q) => $q->where('usuario_id', $usuario)->where('seccion_legajo_id', 4)
                ->where('estado', '!=', 'anulado')->whereDoesntHave('documentosUsuarioPorSubsanaADocumento')
                ->when(! $permitirSensibles, fn ($q) => $q->whereHas('tipoDocumento', fn ($t) => $t->where('es_dato_sensible', false)->where('es_dato_salud', false)))
                ->whereHas('archivo', fn ($archivo) => $archivo->where('estado_antivirus', '!=', 'infectado')))
            ->with('documento')->orderBy('fecha_inicio')->orderBy('id')->get()->map(fn (ExperienciaLaboral $p): array => [
                ...$p->only(['id', 'documento_id', 'entidad_empresa', 'sector', 'cargo', 'tipo_vinculo', 'es_ejercicio_profesional', 'es_docencia_universitaria']),
                'fecha_inicio' => $p->fecha_inicio->format('Y-m-d'), 'fecha_fin' => $p->fecha_fin?->format('Y-m-d'),
                'estado' => ResumenSeccion03::estadoDocumento($p->documento->estado),
            ]);

        return self::calcular($periodos, $corte, $config);
    }

    /** Indicadores reutilizables para el futuro módulo de requisitos, sin umbrales legales en código. */
    public static function indicadores(string $usuario, ?string $corte = null): array
    {
        return self::paraPersona($usuario, $corte)['indicadores'];
    }

    public static function calcular(Collection $periodos, ?string $corte = null, ?array $config = null): array
    {
        $config ??= self::configuracion();
        $hoy = Carbon::parse($corte ?? Carbon::now('America/Lima')->format('Y-m-d'), 'America/Lima')->startOfDay();
        $validos = $periodos->filter(fn (array $p): bool => $p['fecha_inicio'] <= $hoy->format('Y-m-d')
            && ($p['fecha_fin'] === null || $p['fecha_fin'] >= $p['fecha_inicio']) && in_array($p['sector'], ['publico', 'privado'], true))
            ->map(fn (array $p): array => [...$p, 'hasta' => min($p['fecha_fin'] ?? $hoy->format('Y-m-d'), $hoy->format('Y-m-d'))])->values();
        $contados = $validos->filter(fn (array $p): bool => in_array($p['estado'], $config['incluir_pendientes'] ? ['aceptado', 'pendiente'] : ['aceptado'], true));
        $total = fn (Collection $p): array => self::duracion(self::dias(self::unir($p)), $config);
        $unidos = self::unir($validos);
        $huecos = [];
        for ($i = 1; $i < count($unidos); $i++) {
            $dias = (int) $unidos[$i - 1][1]->diffInDays($unidos[$i][0]) - 1;
            if ($dias > 0) {
                $huecos[] = ['desde' => $unidos[$i - 1][1]->copy()->addDay()->format('Y-m-d'), 'hasta' => $unidos[$i][0]->copy()->subDay()->format('Y-m-d'), 'dias' => $dias,
                    'largo' => $dias > $config['hueco_largo_dias']];
            }
        }
        $solapamientos = [];
        $lista = $validos->sortBy('fecha_inicio')->values();
        foreach ($lista as $i => $p) {
            for ($j = $i + 1; $j < $lista->count(); $j++) {
                $otro = $lista[$j];
                if ($otro['fecha_inicio'] > $p['hasta']) {
                    break;
                }
                $inicio = max($p['fecha_inicio'], $otro['fecha_inicio']);
                $fin = min($p['hasta'], $otro['hasta']);
                $solapamientos[] = ['periodos' => [$p['id'], $otro['id']], 'desde' => $inicio, 'hasta' => $fin, 'dias' => (int) Carbon::parse($inicio)->diffInDays(Carbon::parse($fin)) + 1];
            }
        }

        return [
            'fecha_corte' => $hoy->format('Y-m-d'), 'configuracion' => $config,
            'total' => $total($contados), 'publico' => $total($contados->where('sector', 'publico')), 'privado' => $total($contados->where('sector', 'privado')),
            'indicadores' => [
                'anios_ejercicio_profesional' => $total($contados->where('es_ejercicio_profesional', true)),
                'anios_docente_universitario' => $total($contados->where('es_docencia_universitaria', true)),
            ],
            'periodos' => $validos->map(fn (array $p): array => [...$p, 'cuenta' => $contados->contains('id', $p['id'])])->all(),
            'solapamientos' => $solapamientos, 'huecos' => $huecos, 'periodos_invalidos' => $periodos->count() - $validos->count(),
            'sin_clasificar' => $contados->filter(fn (array $p): bool => $p['es_ejercicio_profesional'] === null || $p['es_docencia_universitaria'] === null)->count(),
        ];
    }

    private static function unir(Collection $periodos): array
    {
        $unidos = [];
        foreach ($periodos->sortBy('fecha_inicio') as $p) {
            $inicio = Carbon::parse($p['fecha_inicio'], 'America/Lima')->startOfDay();
            $fin = Carbon::parse($p['hasta'], 'America/Lima')->startOfDay();
            $i = count($unidos) - 1;
            if ($i >= 0 && $inicio->lte($unidos[$i][1]->copy()->addDay())) {
                if ($fin->gt($unidos[$i][1])) {
                    $unidos[$i][1] = $fin;
                }
            } else {
                $unidos[] = [$inicio, $fin];
            }
        }

        return $unidos;
    }

    private static function dias(array $intervalos): int
    {
        return array_sum(array_map(fn (array $p): int => (int) $p[0]->diffInDays($p[1]) + 1, $intervalos));
    }

    private static function duracion(int $dias, array $config): array
    {
        $anio = $config['dias_por_anio'];
        $mes = $config['dias_por_mes'];

        return ['dias_totales' => $dias, 'anios' => intdiv($dias, $anio), 'meses' => intdiv($dias % $anio, $mes),
            'dias' => ($dias % $anio) % $mes, 'anios_decimal' => round($dias / $anio, 2), 'anios_decimal_sin_redondear' => $dias / $anio];
    }
}
