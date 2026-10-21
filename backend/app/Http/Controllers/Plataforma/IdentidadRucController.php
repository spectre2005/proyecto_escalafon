<?php

namespace App\Http\Controllers\Plataforma;

use App\Http\Controllers\Controller;
use App\Rules\Ruc;
use App\Support\ConsultaRuc;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IdentidadRucController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $datos = $request->validate(['ruc' => ['required', 'string', new Ruc], 'actualizar' => ['sometimes', 'boolean']]);

        return response()->json(['data' => ConsultaRuc::consultar($datos['ruc'], $request->boolean('actualizar'))])->header('Cache-Control', 'no-store');
    }
}
