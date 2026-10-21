<?php

namespace App\Support\Identidad;

use Illuminate\Support\Facades\Log;

/**
 * Elige el proveedor de config('identidad.proveedor'). Devuelve null (consulta no disponible) si es «ninguno», si la
 * clave no existe o no implementa ConsultaDni, si es el simulado fuera de local/testing o si es Factiliza sin token:
 * falla de forma cerrada, sin consultar nada ni devolver datos simulados.
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
        if ($clase === ConsultaDniFactiliza::class && ! ConsultaDniFactiliza::configurada()) {
            Log::warning('Consulta de identidad: Factiliza está elegido pero falta FACTILIZA_TOKEN.');

            return null;
        }

        return app($clase);
    }
}
