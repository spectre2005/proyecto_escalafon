<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ConsultaReportes;
use App\Support\ConsultaVencimientos;
use App\Support\PorPagina;
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
        if ($nombre = ConsultaReportes::inactivo($tipo)) {
            return response()->json(['message' => "«{$nombre}» está desactivada en la configuración de la institución."], 409);
        }
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
            'origen' => ['nullable', Rule::in(array_keys(ConsultaVencimientos::ORIGENES))],
            'anio' => 'nullable|integer|between:1900,2200',
            'page' => 'sometimes|integer|min:1',
            // Tabla: 10, 15 y 20 (PorPagina); 50 y 100 se siguen aceptando por compatibilidad con la API.
            'por_pagina' => PorPagina::regla([...PorPagina::OPCIONES, 50, 100]),
            'exportar' => 'sometimes|boolean',
        ]);
        if ($tipo === 'vencimientos') {
            $filtros['dias'] ??= 30;
        }
        // El año actual por defecto; «anio=» vacío (exportación del panel Evaluaciones sin año) pide todos los ciclos.
        if ($tipo === 'evaluaciones' && ! $request->has('anio')) {
            $filtros['anio'] = (int) now()->year;
        }

        return response()->json(ConsultaReportes::listar($request->user(), $tipo, $filtros));
    }
}
