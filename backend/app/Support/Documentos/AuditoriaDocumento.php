<?php

namespace App\Support\Documentos;

use App\Models\AuditoriaEvento;
use App\Models\DocumentoUsuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Cadena de auditoría por documento; se invoca dentro de la transacción que lo crea o revisa. */
final class AuditoriaDocumento
{
    public static function registrar(DocumentoUsuario $documento, Request $request, string $accion, ?array $antes = null, ?array $despues = null): void
    {
        DB::transaction(function () use ($documento, $request, $accion, $antes, $despues): void {
            DocumentoUsuario::query()->whereKey($documento->id)->lockForUpdate()->firstOrFail();
            $previo = AuditoriaEvento::query()->where('entidad', 'documentos_usuario')->where('entidad_id', $documento->id)
                ->orderByDesc('id')->value('hash_registro');
            $datos = [
                'fecha_evento' => now()->format('Y-m-d H:i:s'), 'actor_usuario_id' => $request->user()->getAuthIdentifier(),
                'usuario_titular_id' => $documento->usuario_id, 'accion' => $accion,
                'entidad' => 'documentos_usuario', 'entidad_id' => $documento->id,
                'datos_antes' => $antes === null ? null : json_encode($antes, JSON_THROW_ON_ERROR),
                'datos_despues' => json_encode($despues ?? $documento->getAttributes(), JSON_THROW_ON_ERROR),
                'ip_origen' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 300),
                'request_id' => (string) Str::uuid(), 'hash_previo' => $previo,
            ];
            AuditoriaEvento::query()->create([...$datos, 'hash_registro' => hash('sha256', json_encode($datos, JSON_THROW_ON_ERROR))]);
        });
    }
}
