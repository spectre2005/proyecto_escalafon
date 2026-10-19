<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Support\Auditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/**
 * Anulación con motivo (Directiva, arts. 2.3 y 5.3: no se elimina ni se altera).
 * El registro queda en la base marcado como anulado; una sola vez. Quién lo anuló, cuándo y por qué quedan en la
 * auditoría, en la misma transacción.
 */
trait AnulaConMotivo
{
    protected function anular(Request $request, Model $registro): JsonResource|JsonResponse
    {
        $data = $request->validate(['motivo_anulacion' => 'required|string|min:5|max:1000']);
        if ($registro->anulado) {
            return response()->json(['message' => 'El registro ya está anulado.'], 409);
        }
        DB::transaction(function () use ($registro, $data, $request): void {
            $registro->update(['anulado' => true, 'motivo_anulacion' => trim($data['motivo_anulacion'])]);
            // Las adendas no guardan la persona: es la de su vínculo.
            $titular = $registro->getAttribute('usuario_id') ?? (method_exists($registro, 'vinculoLaboral') ? $registro->vinculoLaboral?->usuario_id : null);
            Auditoria::registrar($request, $registro->getTable().'.anular', $registro->getTable(), $registro->getKey(), $titular,
                ['anulado' => false], ['anulado' => true, 'motivo_anulacion' => $registro->motivo_anulacion]);
        });

        return new JsonResource($registro->refresh());
    }
}
