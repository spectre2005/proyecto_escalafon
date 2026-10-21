<?php

namespace App\Http\Middleware;

use App\Models\Usuario;
use App\Support\PasswordTemporal;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Una cuenta con contraseña temporal (debe_cambiar_password) no usa el sistema hasta cambiarla: toda ruta protegida
 * responde 403 con «codigo» para que el frontend muestre el cambio de contraseña. Las rutas de v1/auth (sesión, cierre
 * y cambio de contraseña) no pasan por aquí.
 */
class ExigeCambioPassword
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();
        if ($usuario instanceof Usuario && PasswordTemporal::vencida($usuario)) {
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return response()->json(['message' => PasswordTemporal::MENSAJE, 'codigo' => 'password_temporal_vencida'], 401);
        }
        if ($usuario instanceof Usuario && $usuario->debe_cambiar_password) {
            return response()->json(['message' => 'Debes cambiar tu contraseña temporal antes de continuar.', 'codigo' => 'cambio_password_requerido'], 403);
        }

        return $next($request);
    }
}
