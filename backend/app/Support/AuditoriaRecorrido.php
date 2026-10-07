<?php

namespace App\Support;

use App\Models\AuditoriaEvento;
use App\Models\RecorridoLegajo;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class AuditoriaRecorrido
{
    /** Se invoca con el recorrido bloqueado dentro de su transacción. */
    public static function registrar(RecorridoLegajo $recorrido, Request $request, string $titular, string $accion, array $antes, array $despues): void
    {
        $previo = AuditoriaEvento::query()->where('entidad', 'recorridos_legajo')->where('entidad_id', $recorrido->id)->orderByDesc('id')->value('hash_registro');
        $datos = [
            'fecha_evento' => now()->format('Y-m-d H:i:s'), 'actor_usuario_id' => $request->user()->getAuthIdentifier(),
            'usuario_titular_id' => $titular, 'accion' => 'recorrido.'.$accion, 'entidad' => 'recorridos_legajo', 'entidad_id' => $recorrido->id,
            'datos_antes' => json_encode($antes, JSON_THROW_ON_ERROR), 'datos_despues' => json_encode($despues, JSON_THROW_ON_ERROR),
            'ip_origen' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 300),
            'request_id' => (string) Str::uuid(), 'hash_previo' => $previo,
        ];
        AuditoriaEvento::query()->create([...$datos, 'hash_registro' => hash('sha256', json_encode($datos, JSON_THROW_ON_ERROR))]);
    }
}
