<?php

namespace App\Support;

use App\Models\AuditoriaEvento;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * con_discapacidad es un dato sensible (el servidor o alguno de sus hijos, Directiva punto 1 k): solo se cambia con
 * discapacidad.editar y cada cambio queda en auditoria_eventos (quién, cuándo, antes y después). Enviar el mismo
 * valor que ya tiene no es un cambio: no exige el permiso ni se audita.
 */
final class CambioDiscapacidad
{
    public const PERMISO = 'discapacidad.editar';

    public const CAMPO = 'con_discapacidad';

    /** Valor validado con la regla boolean (true, false, 1, 0, "1", "0") o null, como ?bool. */
    public static function valor(mixed $valor): ?bool
    {
        return $valor === null ? null : filter_var($valor, FILTER_VALIDATE_BOOLEAN);
    }

    /** 403 si el valor cambia y el actor no tiene el permiso. */
    public static function autorizar(Usuario $actor, ?bool $antes, ?bool $despues): void
    {
        abort_if($antes !== $despues && ! $actor->can(self::PERMISO), 403,
            'Registrar o cambiar la discapacidad requiere el permiso discapacidad.editar (administración de la ORH).');
    }

    /** Deja el evento en auditoria_eventos si el valor cambió. */
    public static function auditar(string $titularId, ?bool $antes, ?bool $despues, Usuario $actor, ?Request $request = null): void
    {
        if ($antes === $despues) {
            return;
        }
        $datos = [
            'fecha_evento' => now()->format('Y-m-d H:i:s'), 'actor_usuario_id' => $actor->getKey(), 'usuario_titular_id' => $titularId,
            'accion' => 'persona.con_discapacidad', 'entidad' => 'usuarios', 'entidad_id' => $titularId,
            'datos_antes' => json_encode([self::CAMPO => $antes], JSON_THROW_ON_ERROR),
            'datos_despues' => json_encode([self::CAMPO => $despues], JSON_THROW_ON_ERROR),
            'ip_origen' => $request?->ip(), 'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 300) : null,
            'request_id' => (string) Str::uuid(),
            'hash_previo' => AuditoriaEvento::query()->where('entidad', 'usuarios')->where('entidad_id', $titularId)
                ->orderByDesc('fecha_evento')->orderByDesc('id')->value('hash_registro'),
        ];
        AuditoriaEvento::query()->create([...$datos, 'hash_registro' => hash('sha256', json_encode($datos, JSON_THROW_ON_ERROR))]);
    }
}
