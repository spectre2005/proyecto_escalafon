<?php

namespace App\Support;

use App\Models\RequerimientoDocumento;
use App\Models\Usuario;
use App\Models\VinculoLaboral;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Alertas de consulta: no modifica vigencias ni estados del legajo. */
final class ConsultaVencimientos
{
    public static function listar(Usuario $actor, array $filtros): array
    {
        $hoy = CarbonImmutable::now('America/Lima')->startOfDay();
        $dias = (int) ($filtros['dias'] ?? 30);
        $alertas = self::alertas($actor, $hoy, $dias, $filtros['origen'] ?? null);
        $hoyTexto = $hoy->toDateString();
        $agrupadas = DB::query()->fromSub($alertas, 'alertas')->select('usuario_id')
            ->selectRaw('COUNT(*) as total_alertas, MIN(fecha) as primera_fecha')
            ->selectRaw("SUM(CASE WHEN DATE(fecha) < ? AND origen <> 'requerimiento' THEN 1 ELSE 0 END) as vencidos", [$hoyTexto])
            ->selectRaw("SUM(CASE WHEN DATE(fecha) < ? AND origen = 'requerimiento' THEN 1 ELSE 0 END) as fuera_de_plazo", [$hoyTexto])
            ->selectRaw('SUM(CASE WHEN DATE(fecha) >= ? THEN 1 ELSE 0 END) as por_vencer', [$hoyTexto])->groupBy('usuario_id');
        // Área con el criterio de la ficha (PuestoActual): el vínculo vigente de inicio más reciente (VigenciaLaboral, con
        // adendas); sin vigente, el abierto más reciente; sin vínculo abierto, la unidad registrada en la persona.
        $vigente = VigenciaLaboral::consulta(VinculoLaboral::query()->whereColumn('vinculos_laborales.usuario_id', 'u.id'), $hoyTexto)
            ->orderByDesc('vinculos_laborales.fecha_inicio')->orderByDesc('vinculos_laborales.id')->limit(1)->select('vinculos_laborales.id')->toBase();
        $abierto = DB::table('vinculos_laborales as va')->select('va.id')->whereColumn('va.usuario_id', 'u.id')->where('va.anulado', false)
            ->where(fn (Builder $q) => $q->whereNull('va.fecha_cese')->orWhereDate('va.fecha_cese', '>=', $hoyTexto))
            ->orderByDesc('va.fecha_inicio')->orderByDesc('va.id')->limit(1);
        $personas = DB::query()->fromSub($agrupadas, 'a')->join('usuarios as u', 'u.id', '=', 'a.usuario_id')
            ->select('a.*', 'u.numero_doc_identidad', 'u.tipo_personal', 'u.unidad_organica_id as unidad_persona')
            ->selectRaw("TRIM(u.nombres || ' ' || u.apellido_paterno || ' ' || COALESCE(u.apellido_materno, '')) as nombre")
            ->selectRaw("COALESCE(({$vigente->toSql()}), ({$abierto->toSql()})) as vinculo_actual", [...$vigente->getBindings(), ...$abierto->getBindings()]);
        // Con vínculo, su unidad (aunque esté vacía, como en la ficha); sin vínculo, la de la persona.
        $conArea = DB::query()->fromSub($personas, 'p')->leftJoin('vinculos_laborales as actual', 'actual.id', '=', 'p.vinculo_actual')
            ->select('p.*')->selectRaw('CASE WHEN p.vinculo_actual IS NULL THEN p.unidad_persona ELSE actual.unidad_organica_id END as unidad_id');
        $consulta = DB::query()->fromSub($conArea, 'p')->leftJoin('unidades_organicas as area', 'area.id', '=', 'p.unidad_id')
            ->select('p.usuario_id', 'p.total_alertas', 'p.primera_fecha', 'p.vencidos', 'p.fuera_de_plazo', 'p.por_vencer', 'p.numero_doc_identidad',
                'p.tipo_personal', 'p.nombre', 'p.unidad_id', 'area.nombre as area');
        if (! empty($filtros['q'])) {
            $busqueda = '%'.mb_strtolower(trim($filtros['q'])).'%';
            $consulta->where(fn (Builder $q) => $q->whereRaw('LOWER(p.nombre) LIKE ?', [$busqueda])->orWhere('p.numero_doc_identidad', 'like', $busqueda));
        }
        foreach (['tipo_personal', 'unidad_id'] as $campo) {
            if (! empty($filtros[$campo])) {
                $consulta->where('p.'.$campo, $filtros[$campo]);
            }
        }
        // Los contadores salen de las mismas alertas visibles (nunca cuentan lo que el actor no puede ver) y de todos los
        // filtros salvo Estado: muestran el panorama aunque se elija un estado.
        $resumen = ['personas' => (clone $consulta)->count(),
            'vencidos' => (clone $consulta)->where('p.vencidos', '>', 0)->count(),
            'fuera_de_plazo' => (clone $consulta)->where('p.vencidos', 0)->where('p.fuera_de_plazo', '>', 0)->count(),
            'por_vencer' => (clone $consulta)->where('p.vencidos', 0)->where('p.fuera_de_plazo', 0)->count()];
        // Estado de la persona: vencido si tiene algo vencido; si no, fuera de plazo si tiene requerimientos atrasados; si no,
        // solo por vencer.
        match ($filtros['estado'] ?? null) {
            'vencido' => $consulta->where('p.vencidos', '>', 0),
            'fuera_de_plazo' => $consulta->where('p.vencidos', 0)->where('p.fuera_de_plazo', '>', 0),
            'por_vencer' => $consulta->where('p.vencidos', 0)->where('p.fuera_de_plazo', 0),
            default => null,
        };
        $pagina = $consulta->orderBy('p.primera_fecha')->orderBy('p.usuario_id')->paginate(PorPagina::de($filtros))->withQueryString();
        $ids = $pagina->getCollection()->pluck('usuario_id');
        // Cinco motivos por persona mantienen acotada la respuesta aunque un legajo tenga muchos documentos antiguos.
        $ordenadas = DB::query()->fromSub($alertas, 'd')->whereIn('usuario_id', $ids)->select('d.*')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY usuario_id ORDER BY fecha, origen, alerta_id) as posicion');
        $motivos = DB::query()->fromSub($ordenadas, 'ordenadas')->where('posicion', '<=', 5)->orderBy('fecha')->get()->groupBy('usuario_id');
        $requerimientos = RequerimientoDocumento::query()
            ->whereIn('id', $motivos->flatten()->where('origen', 'requerimiento')->pluck('alerta_id'))
            ->with(['tipoDocumento:id,codigo,seccion_legajo_id,subseccion_legajo_id', 'tipoDocumento.subseccionLegajo:id,codigo'])
            ->get(['id', 'tipo_documento_id'])->keyBy('id');
        $pagina->through(function ($persona) use ($motivos, $hoy, $requerimientos): array {
            $detalle = ($motivos->get($persona->usuario_id) ?? collect())->map(function ($alerta) use ($hoy, $requerimientos): array {
                $dias = (int) $hoy->diffInDays(CarbonImmutable::parse($alerta->fecha, 'America/Lima'), false);

                // id: el del registro de origen (vínculo, certificado…), para enlazar a él desde el panel.
                return ['id' => $alerta->alerta_id, 'origen' => $alerta->origen, 'detalle' => $alerta->detalle, 'fecha' => substr($alerta->fecha, 0, 10),
                    'tipo_documento' => $alerta->origen === 'requerimiento' ? $requerimientos->get($alerta->alerta_id)?->tipoDocumento?->toArray() : null,
                    'estado' => self::estado($alerta->origen, (string) $alerta->fecha, $hoy), 'dias_restantes' => $dias, 'criticidad' => $dias < 7 ? 'rojo' : 'amarillo'];
            })->all();

            return [...(array) $persona, 'total_alertas' => (int) $persona->total_alertas, 'vencidos' => (int) $persona->vencidos,
                'fuera_de_plazo' => (int) $persona->fuera_de_plazo, 'por_vencer' => (int) $persona->por_vencer,
                'estado' => match (true) {
                    $persona->vencidos > 0 => 'vencido', $persona->fuera_de_plazo > 0 => 'fuera_de_plazo', default => 'por_vencer'
                },
                'motivos' => $detalle];
        });

        return [...$pagina->toArray(), 'resumen' => $resumen, 'hoy' => $hoy->toDateString(), 'dias' => $dias,
            'areas' => DB::table('unidades_organicas')->orderBy('nombre')->get(['id', 'nombre']),
            'origenes' => array_values(array_map(fn (string $clave): array => ['id' => $clave, 'nombre' => self::ORIGENES[$clave]], self::origenesVisibles($actor)))];
    }

    /** Motivos de alerta (origen => etiqueta). Los sensibles y los de la sección 07 dependen del permiso de quien consulta. */
    public const ORIGENES = [
        'documento' => 'Documento', 'contrato' => 'Contrato / vínculo', 'requerimiento' => 'Requerimiento',
        'colegiatura' => 'Colegiatura / habilitación', 'discapacidad' => 'Certificado de discapacidad', 'examen_medico' => 'Examen médico ocupacional',
        'periodo_prueba' => 'Período de prueba', 'ratificacion' => 'Ratificación docente', 'desplazamiento' => 'Encargo o destaque',
    ];

    /** Estado de una alerta por su fecha y origen: los requerimientos atrasados están fuera de plazo; lo demás, vencido. */
    public static function estado(string $origen, string $fecha, CarbonImmutable $hoy): string
    {
        return substr($fecha, 0, 10) >= $hoy->toDateString() ? 'por_vencer' : ($origen === 'requerimiento' ? 'fuera_de_plazo' : 'vencido');
    }

    /** Orígenes que puede ver quien consulta (los sensibles, con datos_sensibles.ver; la sección 07, con seccion07.ver). */
    public static function origenesVisibles(Usuario $actor): array
    {
        return array_keys(array_filter([
            'documento' => true, 'contrato' => true, 'colegiatura' => true, 'periodo_prueba' => true,
            'requerimiento' => $actor->can('requerimientos.gestionar'),
            'discapacidad' => $actor->veDatosSensibles(), 'examen_medico' => $actor->veDatosSensibles(),
            'ratificacion' => $actor->can('seccion07.ver'), 'desplazamiento' => $actor->can('seccion07.ver'),
        ]));
    }

    /**
     * Alertas (usuario_id, alerta_id, origen, fecha, detalle) de legajos activos con fecha registrada hasta hoy + $dias.
     * Solo fechas que ya existen: nada se calcula con reglas no confirmadas. Cada fuente excluye lo anulado y lo ya
     * reemplazado; las que el actor no puede ver no se consultan.
     */
    public static function alertas(Usuario $actor, CarbonImmutable $hoy, int $dias, ?string $origen): Builder
    {
        $hasta = $hoy->addDays($dias)->toDateString();
        $legajoActivo = fn (string $columna) => fn (Builder $q) => $q->selectRaw('1')->from('legajos as l')->whereColumn('l.usuario_id', $columna)->where('l.estado_legajo', 'activo');

        $documentos = DB::table('documentos_usuario as d')->join('legajos as l', 'l.id', '=', 'd.legajo_id')
            ->join('tipos_documento as t', 't.id', '=', 'd.tipo_documento_id')->whereColumn('d.usuario_id', 'l.usuario_id')
            ->where('l.estado_legajo', 'activo')->whereNotNull('d.archivo_id')->whereNotIn('d.estado', ['anulado', 'falso', 'pendiente'])
            ->whereNotNull('d.fecha_caducidad')->whereDate('d.fecha_caducidad', '<=', $hasta)
            ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('documentos_usuario as reemplazo')
                ->whereColumn('reemplazo.subsana_a_documento_id', 'd.id')->whereColumn('reemplazo.usuario_id', 'd.usuario_id')
                ->whereColumn('reemplazo.legajo_id', 'd.legajo_id')->whereNotNull('reemplazo.archivo_id')->whereNotIn('reemplazo.estado', ['anulado', 'falso', 'pendiente']))
            ->selectRaw("d.usuario_id, d.id as alerta_id, 'documento' as origen, d.fecha_caducidad as fecha, t.nombre as detalle");
        if (! $actor->veDatosSensibles()) {
            $documentos->whereNotIn('d.seccion_legajo_id', [1, 9, 10])->where('t.es_dato_sensible', false)->where('t.es_dato_salud', false);
        }
        if (! $actor->can('seccion07.ver')) {
            $documentos->where('d.seccion_legajo_id', '!=', 7);
        }
        if (! $actor->can('seccion08.sanciones.ver')) {
            $documentos->whereNotIn('t.codigo', ['SAN_RESOLUCION_SANCION', 'SAN_RESOLUCION_RECURSO']);
        }

        // Fin del contrato o de su última adenda vigente: próximos y ya vencidos mientras no haya cese registrado.
        $adenda = DB::table('vinculo_adendas')->select('fecha_fin')->whereColumn('vinculo_laboral_id', 'v.id')->where('anulado', false)
            ->orderByDesc('fecha_inicio')->orderByDesc('created_at')->orderByDesc('id')->limit(1);
        $vinculos = DB::table('vinculos_laborales as v')->where('v.anulado', false)->whereNull('v.fecha_cese')
            ->whereDate('v.fecha_inicio', '<=', $hoy->toDateString())->whereExists($legajoActivo('v.usuario_id'))
            ->selectRaw("v.usuario_id, v.id as alerta_id, 'contrato' as origen, COALESCE(({$adenda->toSql()}), v.fecha_fin_prevista) as fecha, v.nombre_puesto as detalle", $adenda->getBindings());
        $contratos = DB::query()->fromSub($vinculos, 'v')->whereNotNull('fecha')->whereDate('fecha', '<=', $hasta);

        // Requerimientos esperando a la persona (abierto u observado): próximos a su plazo y fuera de plazo. En revisión
        // espera a la ORH; aceptado y cancelado están cerrados.
        $requerimientos = DB::table('requerimientos_documentos as r')->whereNull('r.cerrado_en')->whereIn('r.estado', ['abierto', 'observado'])
            ->whereRaw('DATE(COALESCE(r.prorroga_hasta, r.fecha_limite)) <= ?', [$hasta])->whereExists($legajoActivo('r.usuario_id'))
            ->selectRaw("r.usuario_id, r.id as alerta_id, 'requerimiento' as origen, COALESCE(r.prorroga_hasta, r.fecha_limite) as fecha, r.descripcion as detalle");

        // Colegiatura o habilitación con fecha registrada; una posterior con vigencia mayor la reemplaza.
        $colegiaturas = DB::table('colegiaturas as c')->where('c.anulado', false)->whereNotNull('c.vigente_hasta')->whereDate('c.vigente_hasta', '<=', $hasta)
            ->whereExists($legajoActivo('c.usuario_id'))
            ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('colegiaturas as c2')->whereColumn('c2.usuario_id', 'c.usuario_id')
                ->where('c2.anulado', false)->whereColumn('c2.vigente_hasta', '>', 'c.vigente_hasta'))
            ->selectRaw("c.usuario_id, c.id as alerta_id, 'colegiatura' as origen, c.vigente_hasta as fecha, c.colegio_profesional as detalle");

        // Certificado de discapacidad (del servidor o de un familiar) con fecha; sin fecha es permanente. Lo reemplaza
        // otro posterior del mismo titular con vigencia mayor o permanente.
        $discapacidad = DB::table('certificados_discapacidad as cd')->where('cd.anulado', false)->whereNotNull('cd.vigente_hasta')->whereDate('cd.vigente_hasta', '<=', $hasta)
            ->whereExists($legajoActivo('cd.usuario_id'))
            ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('certificados_discapacidad as cd2')->whereColumn('cd2.usuario_id', 'cd.usuario_id')
                ->where('cd2.anulado', false)->whereColumn('cd2.id', '!=', 'cd.id')
                ->where(fn (Builder $mismo) => $mismo->whereColumn('cd2.familiar_id', 'cd.familiar_id')->orWhere(fn (Builder $ambos) => $ambos->whereNull('cd2.familiar_id')->whereNull('cd.familiar_id')))
                ->where(fn (Builder $nuevo) => $nuevo->whereColumn('cd2.vigente_hasta', '>', 'cd.vigente_hasta')
                    ->orWhere(fn (Builder $permanente) => $permanente->whereNull('cd2.vigente_hasta')->whereColumn('cd2.created_at', '>', 'cd.created_at'))))
            ->selectRaw("cd.usuario_id, cd.id as alerta_id, 'discapacidad' as origen, cd.vigente_hasta as fecha, CASE WHEN cd.familiar_id IS NULL THEN 'Del servidor' ELSE 'De un familiar' END as detalle");

        // Examen médico ocupacional (sección 10) con fecha de vencimiento; uno posterior con vencimiento mayor lo reemplaza.
        $examenes = DB::table('registros_sst_bienestar as s')->where('s.anulado', false)->where('s.tipo', 'S10_EXAMEN_OCUPACIONAL')
            ->whereNotNull('s.fecha_vencimiento')->whereDate('s.fecha_vencimiento', '<=', $hasta)->whereExists($legajoActivo('s.usuario_id'))
            ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('registros_sst_bienestar as s2')->whereColumn('s2.usuario_id', 's.usuario_id')
                ->where('s2.anulado', false)->where('s2.tipo', 'S10_EXAMEN_OCUPACIONAL')->whereColumn('s2.fecha_vencimiento', '>', 's.fecha_vencimiento'))
            ->selectRaw("s.usuario_id, s.id as alerta_id, 'examen_medico' as origen, s.fecha_vencimiento as fecha, 'Vencimiento del examen' as detalle");

        // Período de prueba con resultado pendiente, de un vínculo no anulado ni cesado.
        $pruebas = DB::table('periodos_prueba as pp')->join('vinculos_laborales as vp', 'vp.id', '=', 'pp.vinculo_laboral_id')
            ->where('pp.anulado', false)->where('pp.resultado', 'pendiente')->whereDate('pp.fecha_fin', '<=', $hasta)
            ->where('vp.anulado', false)->whereNull('vp.fecha_cese')->whereColumn('vp.usuario_id', 'pp.usuario_id')->whereExists($legajoActivo('pp.usuario_id'))
            ->selectRaw("pp.usuario_id, pp.id as alerta_id, 'periodo_prueba' as origen, pp.fecha_fin as fecha, vp.nombre_puesto as detalle");

        // Fin del período de ratificación docente, mientras el resultado siga pendiente.
        $ratificaciones = DB::table('ratificaciones_docentes as rd')->where('rd.anulado', false)->where('rd.resultado', 'pendiente')
            ->whereDate('rd.fecha_vencimiento_periodo', '<=', $hasta)->whereExists($legajoActivo('rd.usuario_id'))
            ->selectRaw("rd.usuario_id, rd.id as alerta_id, 'ratificacion' as origen, rd.fecha_vencimiento_periodo as fecha, 'Fin del período de nombramiento' as detalle");

        // Encargos y destaques temporales: solo próximos a su fin. No hay un dato que diga que siguen activos después de
        // esa fecha (el registro no tiene estado ni cierre), así que uno con fecha de fin pasada se considera terminado.
        $desplazamientos = DB::table('desplazamientos as dz')->where('dz.anulado', false)->whereIn('dz.tipo', ['destaque', 'encargo_puesto', 'encargo_funciones'])
            ->whereNotNull('dz.fecha_fin')->whereDate('dz.fecha_fin', '>=', $hoy->toDateString())->whereDate('dz.fecha_fin', '<=', $hasta)
            ->whereExists($legajoActivo('dz.usuario_id'))
            ->selectRaw("dz.usuario_id, dz.id as alerta_id, 'desplazamiento' as origen, dz.fecha_fin as fecha, CASE dz.tipo WHEN 'destaque' THEN 'Destaque' WHEN 'encargo_puesto' THEN 'Encargo de puesto' ELSE 'Encargo de funciones' END as detalle");

        $fuentes = ['documento' => $documentos, 'contrato' => $contratos, 'requerimiento' => $requerimientos, 'colegiatura' => $colegiaturas,
            'discapacidad' => $discapacidad, 'examen_medico' => $examenes, 'periodo_prueba' => $pruebas, 'ratificacion' => $ratificaciones,
            'desplazamiento' => $desplazamientos];
        // Solo las fuentes que el actor puede ver; un origen no permitido devuelve una consulta vacía (sin revelar nada).
        $visibles = array_intersect_key($fuentes, array_flip(self::origenesVisibles($actor)));
        if ($origen !== null && $origen !== '') {
            return $visibles[$origen] ?? $documentos->whereRaw('1 = 0');
        }
        $union = array_shift($visibles);
        foreach ($visibles as $fuente) {
            $union->unionAll($fuente);
        }

        return $union;
    }
}
