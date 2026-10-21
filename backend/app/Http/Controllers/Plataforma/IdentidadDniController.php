<?php

namespace App\Http\Controllers\Plataforma;

use App\Http\Controllers\Controller;
use App\Support\ConsultaDni;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IdentidadDniController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $datos = $request->validate(['dni' => ['required', 'string', 'regex:/\A[0-9]{8}\z/']]);

        return response()->json(['data' => ConsultaDni::consultar($datos['dni'])])->header('Cache-Control', 'no-store');
    }
}
