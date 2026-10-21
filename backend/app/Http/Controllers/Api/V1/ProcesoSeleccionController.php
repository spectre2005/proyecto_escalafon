<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ProcesoSeleccion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProcesoSeleccionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'regimen_normativo_id' => 'required|uuid|exists:regimenes_normativos,id',
            'q' => 'nullable|string|max:100',
        ]);
        $consulta = ProcesoSeleccion::query()->where('regimen_normativo_id', $datos['regimen_normativo_id']);
        if (filled($datos['q'] ?? null)) {
            $termino = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtoupper(trim($datos['q'])));
            $consulta->whereRaw("UPPER(codigo) LIKE ? ESCAPE '!'", ['%'.$termino.'%']);
        }
        $procesos = $consulta->orderByDesc('anio')->orderBy('codigo')->limit(100)->get(['id', 'codigo', 'anio', 'regimen_normativo_id']);

        return response()->json(['data' => $procesos]);
    }
}
