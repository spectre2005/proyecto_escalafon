<?php

namespace App\Support;

use App\Models\AuditoriaEvento;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Se invoca dentro de la transacción y con el registro de negocio bloqueado por el servicio. */
final class AuditoriaInstitucional
{
    public static function registrar(string $entidad, string $id, string $accion, ?Request $request, ?string $titular, ?array $antes, array $despues): void
    {
        $previo = AuditoriaEvento::query()->where('entidad', $entidad)->where('entidad_id', $id)->orderByDesc('id')->value('hash_registro');
        $datos = [
            'fecha_evento' => now()->format('Y-m-d H:i:s'), 'actor_usuario_id' => $request?->user()?->id,
            'usuario_titular_id' => $titular, 'accion' => $accion, 'entidad' => $entidad, 'entidad_id' => $id,
            'datos_antes' => $antes === null ? null : json_encode($antes, JSON_THROW_ON_ERROR),
            'datos_despues' => json_encode($despues, JSON_THROW_ON_ERROR), 'ip_origen' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 300) : null,
            'request_id' => (string) Str::uuid(), 'hash_previo' => $previo,
        ];
        AuditoriaEvento::query()->create([...$datos, 'hash_registro' => hash('sha256', json_encode($datos, JSON_THROW_ON_ERROR))]);
    }
}
