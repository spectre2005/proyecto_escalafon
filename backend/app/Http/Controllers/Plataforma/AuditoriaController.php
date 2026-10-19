<?php

namespace App\Http\Controllers\Plataforma;

use App\Http\Controllers\Controller;
use App\Models\Plataforma\AuditoriaPlataforma;
use App\Support\ConsultaAuditoria;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AuditoriaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->consulta($request)->paginate(20)->through(fn ($r): array => ['id' => $r->id, 'fecha' => $r->created_at,
            'actor' => $r->administrador?->nombre ?? 'Sistema', 'accion' => $r->accion, 'institucion' => $r->institucion?->nombre,
            'registro_id' => $r->institucion_id, 'motivo' => $r->motivo, 'ip' => $r->ip_origen,
            'antes' => ConsultaAuditoria::redactar($r->datos_antes), 'despues' => ConsultaAuditoria::redactar($r->datos_despues)]));
    }

    public function exportar(Request $request): BinaryFileResponse
    {
        return ConsultaAuditoria::csv($this->consulta($request), fn ($r): array => [$r->created_at?->toIso8601String(), $r->administrador?->nombre ?? 'Sistema', $r->accion, $r->institucion?->nombre, $r->institucion_id, $r->motivo, $r->ip_origen],
            ['Fecha UTC', 'Actor', 'Acción', 'Institución', 'ID institución', 'Motivo', 'IP'], 'auditoria-plataforma.csv');
    }

    private function consulta(Request $request): Builder
    {
        $f = ConsultaAuditoria::filtros($request);

        return ConsultaAuditoria::fechas(AuditoriaPlataforma::query(), 'created_at', $f)->with(['administrador:id,nombre', 'institucion:id,nombre'])
            ->when($f['accion'] ?? null, fn ($q, $v) => $q->whereRaw('LOWER(accion) LIKE ?', ['%'.mb_strtolower($v).'%']))
            ->when($f['institucion'] ?? null, fn ($q, $v) => $q->whereHas('institucion', fn ($i) => $i->whereRaw("LOWER(nombre || ' ' || slug) LIKE ?", ['%'.mb_strtolower($v).'%'])))
            ->when($f['institucion_id'] ?? null, fn ($q, $v) => $q->where('institucion_id', $v))
            ->when($f['actor'] ?? null, fn ($q, $v) => $q->whereHas('administrador', fn ($a) => $a->whereRaw("LOWER(nombre || ' ' || email) LIKE ?", ['%'.mb_strtolower($v).'%'])))
            ->orderByDesc('created_at')->orderByDesc('id');
    }
}
