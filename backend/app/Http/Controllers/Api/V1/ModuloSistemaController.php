<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Plataforma\Institucion;
use App\Support\ModulosSistema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ModuloSistemaController extends Controller
{
    public function indexPlataforma(Request $request, string $institucion): JsonResponse
    {
        return $this->index($request, $institucion);
    }

    public function updatePlataforma(Request $request, string $institucion, string $codigo): JsonResponse
    {
        return $this->update($request, $codigo, $institucion);
    }

    public function index(Request $request, ?string $institucion = null): JsonResponse
    {
        $registro = $institucion ? Institucion::query()->findOrFail($institucion) : null;

        return response()->json(['data' => ModulosSistema::estados($registro)])->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, string $codigo, ?string $institucion = null): JsonResponse
    {
        $desconocidos = array_diff(array_keys($request->all()), ['activa', 'activa_actual', 'motivo']);
        if ($desconocidos !== []) {
            throw ValidationException::withMessages(array_fill_keys($desconocidos, 'Campo no permitido.'));
        }
        $data = $request->validate(['activa' => 'required|boolean', 'activa_actual' => 'required|boolean', 'motivo' => 'required|string|min:5|max:500']);
        $data['motivo'] = trim($data['motivo']);
        if (mb_strlen($data['motivo']) < 5) {
            throw ValidationException::withMessages(['motivo' => 'Indica un motivo de al menos cinco caracteres.']);
        }
        $registro = $institucion ? Institucion::query()->findOrFail($institucion) : null;

        return response()->json(['data' => ModulosSistema::cambiar($codigo, (bool) $data['activa'], (bool) $data['activa_actual'], $data['motivo'], $request, $registro)])
            ->header('Cache-Control', 'private, no-store');
    }
}
