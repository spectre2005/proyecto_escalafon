<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ConsultaReportes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReporteController extends Controller
{
    public function opciones(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('personal.ver') || $request->user()->can('legajo.ver'), 403);

        return response()->json(['data' => ConsultaReportes::opciones($request->user())]);
    }

    public function show(Request $request, string $tipo): JsonResponse
    {
        abort_unless(array_key_exists($tipo, ConsultaReportes::catalogo()), 404);
        abort_unless(ConsultaReportes::puede($request->user(), $tipo), 403);
        $estados = array_keys(ConsultaReportes::catalogo()[$tipo]['estados']);
        $filtros = $request->validate([
            'contenido' => 'nullable|string|max:120',
            'q' => 'nullable|string|max:120',
            'tipo_personal' => 'nullable|in:docente,administrativo',
            'unidad_id' => 'nullable|uuid|exists:unidades_organicas,id',
            'regimen_id' => 'nullable|uuid|exists:regimenes_normativos,id',
            'nivel' => 'nullable|string|max:30',
            'modalidad' => 'nullable|string|max:25',
            'estado' => ['nullable', Rule::in($estados)],
            'seccion' => 'nullable|integer|between:1,11',
            'desde' => 'nullable|date_format:Y-m-d',
            'hasta' => ['nullable', 'date_format:Y-m-d', ...($request->filled('desde') ? ['after_or_equal:desde'] : [])],
            'dias' => 'sometimes|integer|min:1|max:365',
            'anio' => 'nullable|integer|between:1900,2200',
            'page' => 'sometimes|integer|min:1',
            'por_pagina' => 'sometimes|integer|in:20,50,100',
            'exportar' => 'sometimes|boolean',
        ]);
        if ($tipo === 'vencimientos') {
            $filtros['dias'] ??= 30;
        }
        if ($tipo === 'evaluaciones') {
            $filtros['anio'] ??= (int) now()->year;
        }

        return response()->json(ConsultaReportes::listar($request->user(), $tipo, $filtros));
    }
}
