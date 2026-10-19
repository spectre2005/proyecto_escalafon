<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ParametroSistema;
use App\Support\ConfiguracionParametros;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ParametrosPanelController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => [...ConfiguracionParametros::datos(), 'historial_id' => ParametroSistema::query()->where('clave', ConfiguracionParametros::VERSION)->value('id')]]);
    }

    public function update(Request $request): JsonResponse
    {
        return response()->json(['data' => ConfiguracionParametros::guardar($request)]);
    }
}
