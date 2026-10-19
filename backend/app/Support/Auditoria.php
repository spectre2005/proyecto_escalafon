<?php

namespace App\Support;

use App\Models\AuditoriaEvento;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Evento en auditoria_eventos para registros que no son documentos (personas, legajos, vínculos, familiares…), con la
 * misma cadena por entidad que AuditoriaDocumento (hash_previo → hash_registro). Se invoca dentro de la transacción del
 * cambio: si el cambio se deshace, el evento también. Nunca guarda la contraseña.
 */
final class Auditoria
{
    private const OCULTOS = ['password', 'remember_token'];

    /**
     * @param  array<string, mixed>|null  $antes  null en un alta
     * @param  array<string, mixed>|null  $despues  null en un borrado
     */
    public static function registrar(Request $request, string $accion, string $entidad, string $entidadId, ?string $titularId, ?array $antes = null, ?array $despues = null): void
    {
        $datos = [
            'fecha_evento' => now()->format('Y-m-d H:i:s'), 'actor_usuario_id' => $request->user()->getAuthIdentifier(),
            'usuario_titular_id' => $titularId, 'accion' => $accion, 'entidad' => $entidad, 'entidad_id' => $entidadId,
            'datos_antes' => $antes === null ? null : json_encode(Arr::except($antes, self::OCULTOS), JSON_THROW_ON_ERROR),
            'datos_despues' => $despues === null ? null : json_encode(Arr::except($despues, self::OCULTOS), JSON_THROW_ON_ERROR),
            'ip_origen' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 300),
            'request_id' => (string) Str::uuid(),
            'hash_previo' => AuditoriaEvento::query()->where('entidad', $entidad)->where('entidad_id', $entidadId)->orderByDesc('id')->value('hash_registro'),
        ];
        AuditoriaEvento::query()->create([...$datos, 'hash_registro' => hash('sha256', json_encode($datos, JSON_THROW_ON_ERROR))]);
    }

    /**
     * Solo los campos que cambian, con su valor anterior y el nuevo.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function diferencia(array $antes, array $despues): array
    {
        $cambios = array_keys(array_filter($despues, fn (mixed $valor, string $campo): bool => ($antes[$campo] ?? null) != $valor, ARRAY_FILTER_USE_BOTH));

        return [Arr::only($antes, $cambios), Arr::only($despues, $cambios)];
    }
}
