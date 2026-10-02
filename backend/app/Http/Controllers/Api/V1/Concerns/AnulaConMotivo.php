<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Anulación con motivo (Directiva, arts. 2.3 y 5.3: no se elimina ni se altera).
 * El registro queda en la base marcado como anulado; una sola vez.
 */
trait AnulaConMotivo
{
    protected function anular(Request $request, Model $registro): JsonResource|JsonResponse
    {
        $data = $request->validate(['motivo_anulacion' => 'required|string|min:5|max:1000']);
        if ($registro->anulado) {
            return response()->json(['message' => 'El registro ya está anulado.'], 409);
        }
        $registro->update(['anulado' => true, 'motivo_anulacion' => trim($data['motivo_anulacion'])]);

        return new JsonResource($registro->refresh());
    }
}
