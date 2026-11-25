<?php

namespace App\Support;

use App\Models\ProgresionCarrera;
use App\Models\RatificacionDocente;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Tiempo de servicio de una persona en la institución, calculado con sus vínculos laborales registrados (todas las
 * etapas, también las de legajos pasivos) a una fecha de corte. Días inclusivos; los vínculos que se superponen se
 * cuentan una sola vez.
 *
 * Pendiente del cliente (config/legajo.php, sobrescribible en parametros_sistema): si se restan las licencias sin goce y
 * las suspensiones, y hasta cuándo cuenta un vínculo con plazo vencido sin cese registrado. Por defecto no se resta nada
 * y un plazo vencido cuenta hasta su fin previsto: el resultado es «según vínculos registrados», no un cómputo oficial.
 * Días por mes y por año: los mismos parámetros que la experiencia (exp_dias_por_mes, exp_dias_por_anio).
 */
final class TiempoServicio
{
    /** Tipos de vínculo (vinculos_laborales.regimen_laboral) con su etiqueta, en el orden en que se muestran. */
    public const TIPOS = RegimenesLaborales::TIPOS_VINCULO;

    /** @return array{restar_licencias_sin_goce: bool, restar_suspensiones: bool, vencido_hasta_corte: bool, dias_por_mes: int, dias_por_anio: int} */
    public static function configuracion(): array
    {
        $experiencia = TiempoExperiencia::configuracion();

        return ['restar_licencias_sin_goce' => Parametros::entero('ts_restar_licencias_sin_goce') === 1,
            'restar_suspensiones' => Parametros::entero('ts_restar_suspensiones') === 1,
            'vencido_hasta_corte' => Parametros::entero('ts_vencido_hasta_corte') === 1,
            'dias_por_mes' => $experiencia['dias_por_mes'], 'dias_por_anio' => $experiencia['dias_por_anio']];
    }

    /**
     * Relaciones que necesita paraPersona(), para cargarlas de una vez en un listado. Sin el alcance de etapa: el tiempo
     * de servicio suma también los vínculos de etapas anteriores (reingreso). Las sanciones solo se consultan si la
     * institución descuenta suspensiones y quien consulta puede verlas; si no, paraPersona() lo indica.
     */
    public static function relaciones(array $config, bool $veSanciones): array
    {
        return array_filter([
            'vinculosLaborales' => fn ($q) => $q->where('anulado', false)->with(['vinculoAdendas', 'unidadOrganica', 'regimenNormativo'])->orderByDesc('fecha_inicio')->orderByDesc('id'),
            'licenciasPermisosPorUsuario' => fn ($q) => $q->where('anulado', false),
            'sancionesPorUsuario' => $config['restar_suspensiones'] && $veSanciones ? fn ($q) => $q->where('anulado', false) : null,
            'ratificacionesDocentesPorUsuario' => fn ($q) => $q->where('anulado', false),
            'progresionesCarreraPorUsuario' => fn ($q) => $q->where('anulado', false),
        ]);
    }

    public static function paraPersona(Usuario $persona, ?string $corte = null, ?array $config = null): array
    {
        $config ??= self::configuracion();
        $hoy = CarbonImmutable::parse($corte ?? VigenciaLaboral::hoy(), 'America/Lima')->startOfDay();
        $vencidos = 0;
        $periodos = [];
        foreach ($persona->vinculosLaborales->where('anulado', false) as $vinculo) {
            if ($vinculo->fecha_inicio->format('Y-m-d') > $hoy->format('Y-m-d')) {
                continue;
            }
            $fin = self::finComputable($vinculo, $hoy, $config, $vencidos);
            if ($fin < self::dia($vinculo->fecha_inicio)) {
                continue;
            }
            $periodos[] = ['tipo' => $vinculo->regimen_laboral, 'desde' => self::dia($vinculo->fecha_inicio), 'hasta' => $fin];
        }
        $descuentos = self::descuentos($persona, $config, $hoy);
        $total = self::computar(array_map(fn (array $p): array => [$p['desde'], $p['hasta']], $periodos), $descuentos);
        $porTipo = [];
        foreach (array_keys(self::TIPOS) as $tipo) {
            $delTipo = array_values(array_filter($periodos, fn (array $p): bool => $p['tipo'] === $tipo));
            if ($delTipo) {
                $porTipo[$tipo] = self::duracion(self::computar(array_map(fn (array $p): array => [$p['desde'], $p['hasta']], $delTipo), $descuentos)['dias'], $config);
            }
        }
        $ingreso = $periodos ? min(array_column($periodos, 'desde')) : null;

        return [
            'fecha_corte' => $hoy->format('Y-m-d'),
            'ingreso' => $ingreso === null ? null : self::fecha($ingreso),
            'total' => self::duracion($total['dias'], $config),
            'descuento_dias' => $total['descontados'],
            'por_tipo' => $porTipo,
            'vigente' => $persona->vinculosLaborales->contains(fn (VinculoLaboral $v): bool => VigenciaLaboral::vigente($v, $hoy->format('Y-m-d'))),
            'vencidos_sin_cese' => $vencidos,
            'sanciones_omitidas' => $config['restar_suspensiones'] && ! $persona->relationLoaded('sancionesPorUsuario'),
            'categoria' => self::categoriaActual($persona, $hoy, $config),
        ];
    }

    /** Duración en años, meses y días con el texto corto que muestran la tabla y la exportación («13 a 8 m 24 d»). */
    public static function duracion(int $dias, array $config): array
    {
        $anios = intdiv($dias, $config['dias_por_anio']);
        $resto = $dias % $config['dias_por_anio'];
        $meses = intdiv($resto, $config['dias_por_mes']);
        $sueltos = $resto % $config['dias_por_mes'];

        return ['dias_totales' => $dias, 'anios' => $anios, 'meses' => $meses, 'dias' => $sueltos,
            'texto' => $dias === 0 ? '0 d' : trim(($anios ? $anios.' a ' : '').($meses ? $meses.' m ' : '').($sueltos ? $sueltos.' d' : ''))];
    }

    /** Último día que cuenta de un vínculo: cese, o fin previsto (última adenda) si venció, o la fecha de corte. */
    private static function finComputable(VinculoLaboral $vinculo, CarbonImmutable $hoy, array $config, int &$vencidos): int
    {
        $corte = self::dia($hoy);
        if ($vinculo->fecha_cese !== null) {
            return min(self::dia($vinculo->fecha_cese), $corte);
        }
        $fin = VigenciaLaboral::fin($vinculo);
        if ($fin !== null && self::dia($fin) < $corte) {
            $vencidos++;

            return $config['vencido_hasta_corte'] ? $corte : self::dia($fin);
        }

        return $corte;
    }

    /** @return list<array{0: int, 1: int}> */
    private static function descuentos(Usuario $persona, array $config, CarbonImmutable $hoy): array
    {
        $tramos = [];
        $corte = self::dia($hoy);
        if ($config['restar_licencias_sin_goce']) {
            foreach ($persona->licenciasPermisosPorUsuario->where('anulado', false) as $licencia) {
                // La suspensión registrada en la sección 05 se descuenta con las suspensiones, no como licencia.
                if ($licencia->con_goce === false && $licencia->tipo !== 'suspension') {
                    $tramos[] = [self::dia($licencia->fecha_inicio), min(self::dia($licencia->fecha_fin), $corte)];
                }
            }
        }
        if ($config['restar_suspensiones']) {
            foreach ($persona->licenciasPermisosPorUsuario->where('anulado', false)->where('tipo', 'suspension') as $suspension) {
                $tramos[] = [self::dia($suspension->fecha_inicio), min(self::dia($suspension->fecha_fin), $corte)];
            }
            foreach ($persona->relationLoaded('sancionesPorUsuario') ? $persona->sancionesPorUsuario->where('anulado', false) : [] as $sancion) {
                if (in_array($sancion->tipo, Sanciones::CON_SEPARACION, true) && $sancion->fecha_inicio && $sancion->fecha_fin) {
                    $tramos[] = [self::dia($sancion->fecha_inicio), min(self::dia($sancion->fecha_fin), $corte)];
                }
            }
        }

        return array_values(array_filter($tramos, fn (array $t): bool => $t[1] >= $t[0]));
    }

    /**
     * Días computables: la unión de los periodos menos la parte de la unión de descuentos que cae dentro de ellos.
     *
     * @param  list<array{0: int, 1: int}>  $periodos
     * @param  list<array{0: int, 1: int}>  $descuentos
     * @return array{dias: int, descontados: int}
     */
    public static function computar(array $periodos, array $descuentos): array
    {
        $trabajados = self::unir($periodos);
        $restados = self::unir($descuentos);
        $bruto = array_sum(array_map(fn (array $t): int => $t[1] - $t[0] + 1, $trabajados));
        $descontados = 0;
        $i = $j = 0;
        while ($i < count($trabajados) && $j < count($restados)) {
            $desde = max($trabajados[$i][0], $restados[$j][0]);
            $hasta = min($trabajados[$i][1], $restados[$j][1]);
            if ($desde <= $hasta) {
                $descontados += $hasta - $desde + 1;
            }
            $trabajados[$i][1] < $restados[$j][1] ? $i++ : $j++;
        }

        return ['dias' => $bruto - $descontados, 'descontados' => $descontados];
    }

    /**
     * @param  list<array{0: int, 1: int}>  $tramos
     * @return list<array{0: int, 1: int}>
     */
    private static function unir(array $tramos): array
    {
        usort($tramos, fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $unidos = [];
        foreach ($tramos as [$desde, $hasta]) {
            $ultimo = count($unidos) - 1;
            if ($ultimo >= 0 && $desde <= $unidos[$ultimo][1] + 1) {
                $unidos[$ultimo][1] = max($unidos[$ultimo][1], $hasta);
            } else {
                $unidos[] = [$desde, $hasta];
            }
        }

        return $unidos;
    }

    /**
     * Categoría docente (última ratificación, nombramiento o promoción que la cambió) o nivel de carrera (última
     * progresión), con el tiempo que lleva en ella a la fecha de corte. Null si no hay registros.
     */
    private static function categoriaActual(Usuario $persona, CarbonImmutable $hoy, array $config): ?array
    {
        $corte = self::dia($hoy);
        $ratificaciones = $persona->ratificacionesDocentesPorUsuario->where('anulado', false)
            // Cuenta el nombramiento y lo que se resolvió a favor; una ratificación pendiente o denegada no fija categoría.
            ->filter(fn (RatificacionDocente $r): bool => self::dia($r->fecha_inicio_periodo) <= $corte
                && ($r->tipo === 'nombramiento' || ! in_array($r->resultado, ['pendiente', 'no_ratificado'], true)))
            ->sortBy(fn (RatificacionDocente $r): string => $r->fecha_inicio_periodo->format('Y-m-d').$r->id)->values();
        if ($ratificaciones->isNotEmpty()) {
            $actual = $ratificaciones->last()->categoria_nueva;
            // Desde el primer registro de la racha final con la misma categoría (una ratificación no reinicia el tiempo).
            $desde = $ratificaciones->reverse()->takeUntil(fn (RatificacionDocente $r): bool => $r->categoria_nueva !== $actual)->last()->fecha_inicio_periodo;

            return ['nombre' => 'Docente '.ucfirst((string) $actual), 'desde' => $desde->format('d/m/Y'), 'tiempo' => self::duracion($corte - self::dia($desde) + 1, $config)];
        }
        /** @var Collection<int, ProgresionCarrera> $progresiones */
        $progresiones = $persona->progresionesCarreraPorUsuario->where('anulado', false)
            ->filter(fn (ProgresionCarrera $p): bool => self::dia($p->fecha_efectiva) <= $corte)
            ->sortBy(fn (ProgresionCarrera $p): string => $p->fecha_efectiva->format('Y-m-d').$p->id);
        $ultima = $progresiones->last();

        return $ultima ? ['nombre' => 'Nivel '.$ultima->nivel_nuevo, 'desde' => $ultima->fecha_efectiva->format('d/m/Y'),
            'tiempo' => self::duracion($corte - self::dia($ultima->fecha_efectiva) + 1, $config)] : null;
    }

    /** Número de día (días desde 1970-01-01) para operar con enteros. */
    private static function dia(CarbonInterface $fecha): int
    {
        return intdiv(CarbonImmutable::parse($fecha->format('Y-m-d'), 'UTC')->getTimestamp(), 86400);
    }

    private static function fecha(int $dia): string
    {
        return CarbonImmutable::createFromTimestamp($dia * 86400, 'UTC')->format('Y-m-d');
    }
}
