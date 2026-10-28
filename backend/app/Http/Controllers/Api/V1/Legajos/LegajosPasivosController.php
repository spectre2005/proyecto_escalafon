<?php

namespace App\Http\Controllers\Api\V1\Legajos;

use App\Http\Controllers\Controller;
use App\Models\Legajo;
use App\Support\PorPagina;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LegajosPasivosController extends Controller
{
    public function resumen(): JsonResponse
    {
        return response()->json(['data' => ['total' => Legajo::query()->where('estado_legajo', 'pasivo')->count()]]);
    }

    public function index(Request $request): JsonResponse
    {
        $datos = $request->validate(['q' => 'nullable|string|max:120', 'page' => 'sometimes|integer|min:1', 'por_pagina' => PorPagina::regla()]);
        $consulta = Legajo::query()->where('estado_legajo', 'pasivo')
            ->with('usuario:id,nombres,apellido_paterno,apellido_materno,numero_doc_identidad');
        if (! empty($datos['q'])) {
            $buscar = '%'.mb_strtolower(trim($datos['q'])).'%';
            $consulta->where(function (Builder $query) use ($buscar): void {
                $query->whereRaw('LOWER(numero_legajo) LIKE ?', [$buscar])
                    ->orWhereHas('usuario', fn (Builder $persona): Builder => $persona
                        ->whereRaw("LOWER(nombres || ' ' || apellido_paterno || ' ' || COALESCE(apellido_materno, '')) LIKE ?", [$buscar])
                        ->orWhere('numero_doc_identidad', 'like', $buscar));
            });
        }

        return response()->json($consulta->orderByDesc('fecha_pasivo')->orderByDesc('id')->paginate(PorPagina::de($datos))->through(fn (Legajo $legajo): array => [
            ...$legajo->only(['id', 'usuario_id', 'numero_legajo', 'estado_legajo']),
            'fecha_apertura' => $legajo->fecha_apertura?->format('Y-m-d'), 'fecha_pasivo' => $legajo->fecha_pasivo?->format('Y-m-d'),
            'persona' => $legajo->usuario?->only(['nombres', 'apellido_paterno', 'apellido_materno', 'numero_doc_identidad']),
        ]));
    }
}
