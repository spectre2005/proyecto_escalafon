<?php

namespace App\Support;

use App\Models\Usuario;
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
        $agrupadas = DB::query()->fromSub($alertas, 'alertas')->select('usuario_id')
            ->selectRaw('COUNT(*) as total_alertas, MIN(fecha) as primera_fecha')
            ->selectRaw('SUM(CASE WHEN DATE(fecha) < ? THEN 1 ELSE 0 END) as desactualizados', [$hoy->toDateString()])
            ->selectRaw('SUM(CASE WHEN DATE(fecha) >= ? THEN 1 ELSE 0 END) as por_vencer', [$hoy->toDateString()])->groupBy('usuario_id');
        $unidad = 'COALESCE((SELECT unidad_organica_id FROM vinculos_laborales WHERE usuario_id = u.id AND anulado = ? AND fecha_cese IS NULL ORDER BY fecha_inicio DESC, id DESC LIMIT 1), u.unidad_organica_id)';
        $personas = DB::query()->fromSub($agrupadas, 'a')->join('usuarios as u', 'u.id', '=', 'a.usuario_id')
            ->select('a.*', 'u.numero_doc_identidad', 'u.tipo_personal')
            ->selectRaw("TRIM(u.nombres || ' ' || u.apellido_paterno || ' ' || COALESCE(u.apellido_materno, '')) as nombre")
            ->selectRaw("$unidad as unidad_id", [false]);
        $consulta = DB::query()->fromSub($personas, 'p')->leftJoin('unidades_organicas as area', 'area.id', '=', 'p.unidad_id')
            ->select('p.*', 'area.nombre as area');
        if (! empty($filtros['q'])) {
            $busqueda = '%'.mb_strtolower(trim($filtros['q'])).'%';
            $consulta->where(fn (Builder $q) => $q->whereRaw('LOWER(p.nombre) LIKE ?', [$busqueda])->orWhere('p.numero_doc_identidad', 'like', $busqueda));
        }
        foreach (['tipo_personal', 'unidad_id'] as $campo) {
            if (! empty($filtros[$campo])) {
                $consulta->where('p.'.$campo, $filtros[$campo]);
            }
        }
        if (($filtros['estado'] ?? null) === 'desactualizado') {
            $consulta->where('p.desactualizados', '>', 0);
        } elseif (($filtros['estado'] ?? null) === 'por_vencer') {
            $consulta->where('p.desactualizados', 0);
        }
        $resumen = ['personas' => (clone $consulta)->count(),
            'desactualizados' => (clone $consulta)->where('p.desactualizados', '>', 0)->count(),
            'por_vencer' => (clone $consulta)->where('p.desactualizados', 0)->count()];
        $pagina = $consulta->orderBy('p.primera_fecha')->orderBy('p.usuario_id')->paginate(20)->withQueryString();
        $ids = $pagina->getCollection()->pluck('usuario_id');
        // Cinco motivos por persona mantienen acotada la respuesta aunque un legajo tenga muchos documentos antiguos.
        $ordenadas = DB::query()->fromSub($alertas, 'd')->whereIn('usuario_id', $ids)->select('d.*')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY usuario_id ORDER BY fecha, origen, alerta_id) as posicion');
        $motivos = DB::query()->fromSub($ordenadas, 'ordenadas')->where('posicion', '<=', 5)->orderBy('fecha')->get()->groupBy('usuario_id');
        $pagina->through(function ($persona) use ($motivos, $hoy): array {
            $detalle = ($motivos->get($persona->usuario_id) ?? collect())->map(function ($alerta) use ($hoy): array {
                $dias = (int) $hoy->diffInDays(CarbonImmutable::parse($alerta->fecha, 'America/Lima'), false);

                return ['origen' => $alerta->origen, 'detalle' => $alerta->detalle, 'fecha' => substr($alerta->fecha, 0, 10),
                    'dias_restantes' => $dias, 'criticidad' => $dias < 7 ? 'rojo' : 'amarillo'];
            })->all();

            return [...(array) $persona, 'total_alertas' => (int) $persona->total_alertas, 'desactualizados' => (int) $persona->desactualizados, 'por_vencer' => (int) $persona->por_vencer,
                'estado' => $persona->desactualizados > 0 ? 'desactualizado' : 'por_vencer', 'motivos' => $detalle];
        });

        return [...$pagina->toArray(), 'resumen' => $resumen, 'hoy' => $hoy->toDateString(), 'dias' => $dias,
            'areas' => DB::table('unidades_organicas')->orderBy('nombre')->get(['id', 'nombre']),
            'incluye_requerimientos' => $actor->can('sistema.administrar')];
    }

    private static function alertas(Usuario $actor, CarbonImmutable $hoy, int $dias, ?string $origen): Builder
    {
        $documentos = DB::table('documentos_usuario as d')->join('legajos as l', 'l.id', '=', 'd.legajo_id')
            ->join('tipos_documento as t', 't.id', '=', 'd.tipo_documento_id')->whereColumn('d.usuario_id', 'l.usuario_id')
            ->where('l.estado_legajo', 'activo')->whereNotNull('d.archivo_id')->whereNotIn('d.estado', ['anulado', 'falso', 'pendiente'])
            ->whereNotNull('d.fecha_caducidad')->whereDate('d.fecha_caducidad', '<=', $hoy->addDays($dias)->toDateString())
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
        $adenda = DB::table('vinculo_adendas')->select('fecha_fin')->whereColumn('vinculo_laboral_id', 'v.id')->where('anulado', false)
            ->orderByDesc('fecha_inicio')->orderByDesc('created_at')->orderByDesc('id')->limit(1);
        $vinculos = DB::table('vinculos_laborales as v')->where('v.anulado', false)->whereNull('v.fecha_cese')
            ->whereDate('v.fecha_inicio', '<=', $hoy->toDateString())
            ->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('legajos as l')->whereColumn('l.usuario_id', 'v.usuario_id')->where('l.estado_legajo', 'activo'))
            ->selectRaw("v.usuario_id, v.id as alerta_id, 'contrato' as origen, COALESCE(({$adenda->toSql()}), v.fecha_fin_prevista) as fecha, v.nombre_puesto as detalle", $adenda->getBindings());
        $contratos = DB::query()->fromSub($vinculos, 'v')->whereDate('fecha', '>=', $hoy->toDateString())->whereDate('fecha', '<=', $hoy->addDays($dias)->toDateString());
        $requerimientos = DB::table('requerimientos_documentos as r')->whereNull('r.cerrado_en')
            ->whereIn('r.estado', ['abierto', 'pendiente', 'vencido', 'prorrogado'])
            ->whereRaw('DATE(COALESCE(r.prorroga_hasta, r.fecha_limite)) < ?', [$hoy->toDateString()])
            ->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('legajos as l')->whereColumn('l.usuario_id', 'r.usuario_id')->where('l.estado_legajo', 'activo'))
            ->selectRaw("r.usuario_id, r.id as alerta_id, 'requerimiento' as origen, COALESCE(r.prorroga_hasta, r.fecha_limite) as fecha, r.descripcion as detalle");
        if (! $actor->can('sistema.administrar')) {
            $requerimientos->whereRaw('1 = 0');
        }
        $fuentes = ['documento' => $documentos, 'contrato' => $contratos, 'requerimiento' => $requerimientos];
        if ($origen !== null && $origen !== '') {
            return $fuentes[$origen];
        }

        return $documentos->unionAll($contratos)->unionAll($requerimientos);
    }
}
