<?php

namespace App\Support\Identidad;

use Illuminate\Support\Facades\Log;

/**
 * Elige el proveedor de config('identidad.proveedor'). Devuelve null (consulta no disponible) si es «ninguno», si la
 * clave no existe o no implementa ConsultaDni, o si es el simulado fuera de local/testing: falla de forma cerrada, sin
 * consultar nada ni devolver datos simulados.
 */
final class ProveedorIdentidad
{
    public static function actual(): ?ConsultaDni
    {
        $clave = trim((string) config('identidad.proveedor'));
        if ($clave === '' || $clave === 'ninguno') {
            return null;
        }
        $clase = config('identidad.proveedores.'.$clave);
        if (! is_string($clase) || ! is_subclass_of($clase, ConsultaDni::class)) {
            Log::warning('Consulta de identidad: el proveedor configurado no existe.', ['proveedor' => $clave]);

            return null;
        }
        if ($clase === ConsultaDniSimulada::class && ! ConsultaDniSimulada::permitida()) {
            Log::warning('Consulta de identidad: el proveedor simulado está bloqueado en este entorno.', ['entorno' => app()->environment()]);

            return null;
        }

        return app($clase);
    }
}
