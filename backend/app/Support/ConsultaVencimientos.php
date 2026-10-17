<?php

namespace App\Support;

use App\Models\DocumentoUsuario;
use App\Models\Usuario;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Consulta común; filtra y ordena en SQL antes de paginar las tres fuentes. */
final class ConsultaVencimientos
{
    public static function listar(Usuario $actor, array $filtros): LengthAwarePaginator
    {
        $hoy = VigenciaLegajo::hoy();
        $dias = (int) ($filtros['dias'] ?? 30);
        $columnas = "u.id as usuario_id, l.id as legajo_id, l.numero_legajo, TRIM(u.nombres || ' ' || u.apellido_paterno || ' ' || COALESCE(u.apellido_materno, '')) as nombre, u.tipo_personal";
        $unidad = 'COALESCE((SELECT unidad_organica_id FROM vinculos_laborales WHERE usuario_id = u.id AND anulado = ? AND fecha_cese IS NULL ORDER BY fecha_inicio DESC, id DESC LIMIT 1), u.unidad_organica_id)';
        $ciclos = DB::table('ciclos_vigencia_legajo as c')->join('legajos as l', 'l.id', '=', 'c.legajo_id')
            ->join('usuarios as u', 'u.id', '=', 'l.usuario_id')->where('c.actual', true)
            ->selectRaw($columnas)->selectRaw("c.id as id, 'ciclo' as origen, c.fecha_vencimiento as fecha, 'Ciclo de vigencia docente' as detalle")
            ->selectRaw("$unidad as unidad_id", [false]);
        $adenda = VencimientoVinculo::ultimaAdenda();
        $vinculos = DB::table('vinculos_laborales as v')->join('usuarios as u', 'u.id', '=', 'v.usuario_id')
            ->join('legajos as l', 'l.usuario_id', '=', 'u.id')->where('v.anulado', false)->whereNull('v.fecha_cese')
            ->whereDate('v.fecha_inicio', '<=', $hoy->toDateString())->selectRaw($columnas)
            ->selectRaw("v.id as id, 'vinculo' as origen, COALESCE(({$adenda->toSql()}), v.fecha_fin_prevista) as fecha, v.nombre_puesto as detalle", $adenda->getBindings())
            ->addSelect('v.unidad_organica_id as unidad_id');
        $documentos = DocumentoUsuario::query()->join('legajos as l', 'l.id', '=', 'documentos_usuario.legajo_id')
            ->join('usuarios as u', 'u.id', '=', 'documentos_usuario.usuario_id')->whereColumn('l.usuario_id', 'u.id')
            ->join('tipos_documento as t', 't.id', '=', 'documentos_usuario.tipo_documento_id')
            ->where('documentos_usuario.estado', '!=', 'anulado')
            ->whereIn('documentos_usuario.seccion_legajo_id', DB::table('secciones_legajo')->where('activa', true)->select('id'))
            ->selectRaw($columnas)->selectRaw("documentos_usuario.id as id, 'documento' as origen, documentos_usuario.fecha_caducidad as fecha, t.nombre as detalle")
            ->selectRaw("$unidad as unidad_id", [false]);
        // El permiso del tablero no concede acceso a datos sensibles ni a sanciones.
        if (! $actor->veDatosSensibles() || ! $actor->can('seccion08.sanciones.ver') || ! $actor->can('seccion07.ver')) {
            $documentos->where(function ($q) use ($actor): void {
                $q->where(function ($q) use ($actor): void {
                    if (! $actor->veDatosSensibles()) {
                        $q->whereNotIn('t.seccion_legajo_id', [1, 9, 10])->where('t.es_dato_sensible', false)->where('t.es_dato_salud', false);
                    }
                    if (! $actor->can('seccion08.sanciones.ver')) {
                        $q->whereNotIn('t.codigo', ['SAN_RESOLUCION_SANCION', 'SAN_RESOLUCION_RECURSO']);
                    }
                    if (! $actor->can('seccion07.ver')) {
                        $q->where('t.seccion_legajo_id', '!=', 7);
                    }
                });
                if ($actor->can('legajo.propio')) {
                    $q->orWhere('u.id', $actor->id);
                }
            });
        }
        $fuentes = ['ciclo' => $ciclos, 'vinculo' => $vinculos, 'documento' => $documentos->toBase()];
        $seleccion = empty($filtros['origen']) ? $fuentes : [$fuentes[$filtros['origen']]];
        $union = null;
        foreach ($seleccion as $consulta) {
            $consulta->where('l.estado_legajo', 'activo');
            if (! $actor->can('vencimientos.ver')) {
                $consulta->where('u.id', $actor->id);
            }
            if (! empty($filtros['q'])) {
                $busqueda = '%'.mb_strtolower(trim($filtros['q'])).'%';
                $consulta->where(fn (Builder $q) => $q->whereRaw("LOWER(u.nombres || ' ' || u.apellido_paterno || ' ' || COALESCE(u.apellido_materno, '')) LIKE ?", [$busqueda])
                    ->orWhere('u.numero_doc_identidad', 'like', $busqueda)->orWhere('l.numero_legajo', 'like', $busqueda));
            }
            if (! empty($filtros['tipo_personal'])) {
                $consulta->where('u.tipo_personal', $filtros['tipo_personal']);
            }
            $union = $union ? $union->unionAll($consulta) : $consulta;
        }
        $query = DB::query()->fromSub($union, 'vencimientos')->leftJoin('unidades_organicas as area', 'area.id', '=', 'vencimientos.unidad_id')
            ->select('vencimientos.*', 'area.nombre as area')->whereDate('fecha', '>=', $hoy->toDateString())
            ->whereDate('fecha', '<=', $hoy->addDays($dias)->toDateString());
        if (! empty($filtros['unidad_id'])) {
            $query->where('vencimientos.unidad_id', $filtros['unidad_id']);
        }

        return $query->orderBy('fecha')->orderBy('origen')->orderBy('id')->orderBy('legajo_id')->paginate(20)->through(function ($fila) use ($hoy): array {
            $dias = (int) $hoy->diffInDays(CarbonImmutable::parse($fila->fecha, 'America/Lima'), false);

            return [...(array) $fila, 'fecha' => substr($fila->fecha, 0, 10), 'dias_restantes' => $dias, 'criticidad' => $dias < 7 ? 'rojo' : 'amarillo'];
        });
    }
}
