<?php

namespace App\Support\Plataforma;

use App\Models\Plataforma\AuditoriaPlataforma;
use Illuminate\Http\Request;

/** Registro de las operaciones del panel de plataforma: quién, cuándo, sobre qué institución, antes, después y motivo. */
final class AuditoriaOperaciones
{
    public static function registrar(string $accion, ?Request $request, ?string $administradorId, ?string $institucionId = null,
        ?array $antes = null, ?array $despues = null, ?string $motivo = null): void
    {
        AuditoriaPlataforma::query()->create([
            'administrador_id' => $administradorId, 'accion' => $accion, 'institucion_id' => $institucionId,
            'datos_antes' => $antes, 'datos_despues' => $despues, 'motivo' => $motivo,
            'ip_origen' => $request?->ip(), 'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 300) : null,
        ]);
    }
}
