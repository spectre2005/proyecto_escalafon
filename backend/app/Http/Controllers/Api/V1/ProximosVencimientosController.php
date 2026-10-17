<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ConsultaVencimientos;
use App\Support\VigenciaLegajo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProximosVencimientosController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('vencimientos.ver') || $request->user()->can('renovacion.propia'), 403);
        $filtros = $request->validate([
            'dias' => 'sometimes|integer|min:1|max:365', 'origen' => 'nullable|in:ciclo,vinculo,documento',
            'tipo_personal' => 'nullable|in:docente,administrativo', 'unidad_id' => 'nullable|uuid',
            'q' => 'nullable|string|max:120', 'page' => 'sometimes|integer|min:1',
        ]);
        $resultado = ConsultaVencimientos::listar($request->user(), $filtros);

        return response()->json([...$resultado->toArray(), 'hoy' => VigenciaLegajo::hoy()->toDateString(), 'dias' => (int) ($filtros['dias'] ?? 30),
            'areas' => $request->user()->can('vencimientos.ver') ? DB::table('unidades_organicas')->orderBy('nombre')->get(['id', 'nombre']) : []]);
    }
}
