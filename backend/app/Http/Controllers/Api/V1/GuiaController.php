<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Auditoria;
use App\Support\Guia\GuiaUso;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GuiaController extends Controller
{
    public function index(Request $request, GuiaUso $guia): JsonResponse
    {
        return response()->json(['data' => [
            'temas' => array_map($guia->presentar(...), $guia->temasPara($request->user())),
            'ia_activa' => (bool) config('guia.ia_activa'),
            'borrador' => (bool) config('guia.borrador'),
        ]])->header('Cache-Control', 'private, no-store');
    }

    public function preguntar(Request $request, GuiaUso $guia): JsonResponse
    {
        $datos = $request->validate(['pregunta' => 'required|string|min:2|max:300', 'tema_anterior' => 'nullable|string|max:60']);
        $inicio = microtime(true);
        $resultado = $guia->responder($datos['pregunta'], $guia->temasPara($request->user()), $datos['tema_anterior'] ?? null);
        // Sin el texto de la pregunta: alguien podría escribir ahí datos de una persona.
        Auditoria::registrar($request, 'guia.consulta', 'usuarios', (string) $request->user()->getAuthIdentifier(), null, null, [
            'estado' => $resultado['estado'], 'temas' => array_column($resultado['temas'], 'id'),
            'modelo' => (string) config('escaner.modelo'), 'segundos' => round(microtime(true) - $inicio, 1),
        ]);

        return response()->json(['data' => [...$resultado, 'temas' => array_map($guia->presentar(...), $resultado['temas'])]])
            ->header('Cache-Control', 'private, no-store');
    }
}
