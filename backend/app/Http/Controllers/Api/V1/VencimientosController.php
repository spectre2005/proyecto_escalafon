<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ConsultaVencimientos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VencimientosController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'dias' => 'sometimes|integer|min:1|max:365', 'q' => 'nullable|string|max:120',
            'estado' => 'nullable|in:vencido,fuera_de_plazo,por_vencer', 'origen' => ['nullable', Rule::in(array_keys(ConsultaVencimientos::ORIGENES))],
            'tipo_personal' => 'nullable|in:docente,administrativo', 'unidad_id' => 'nullable|uuid', 'page' => 'sometimes|integer|min:1',
        ]);

        return response()->json(ConsultaVencimientos::listar($request->user(), $filtros));
    }
}
