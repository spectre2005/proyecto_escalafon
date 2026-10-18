<?php

namespace App\Http\Middleware;

use App\Models\Plataforma\AdministradorPlataforma;
use Closure;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rutas protegidas del panel de plataforma (después de auth:plataforma), en cada petición:
 * - Cuenta desactivada: cierra la sesión (401), aunque estuviera abierta.
 * - Huella de la contraseña (password_hash_plataforma, la guarda el login): si la contraseña cambió (restablecida por
 *   otro superadministrador) o la sesión no la tiene (anterior a esta comprobación), cierra la sesión (401). Es lo mismo
 *   que AutenticarSesion hace con el guard web de las instituciones, que no cubre este guard.
 * - Contraseña temporal (debe_cambiar_password): solo deja ver la sesión, cerrarla y cambiar la contraseña (403).
 */
class SesionPlataformaValida
{
    public const CLAVE_HUELLA = 'password_hash_plataforma';

    private const SOLO_CON_TEMPORAL = ['api/plataforma/auth/me', 'api/plataforma/auth/logout', 'api/plataforma/auth/password'];

    public function handle(Request $request, Closure $next): Response
    {
        /** @var SessionGuard $guard */
        $guard = Auth::guard('plataforma');
        $administrador = $guard->user();
        if (! $administrador instanceof AdministradorPlataforma) {
            return $next($request);
        }
        if (! $administrador->activo || ! hash_equals(self::huella($administrador), (string) $request->session()->get(self::CLAVE_HUELLA))) {
            $guard->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json(['message' => 'La sesión ya no es válida. Inicia sesión de nuevo.'], 401);
        }
        if ($administrador->debe_cambiar_password && ! $request->is(...self::SOLO_CON_TEMPORAL)) {
            return response()->json(['message' => 'Debes cambiar tu contraseña temporal antes de continuar.', 'codigo' => 'cambio_password_requerido'], 403);
        }

        return $next($request);
    }

    /** Huella de la contraseña actual (HMAC con la clave de la aplicación, como el guard web). */
    public static function huella(AdministradorPlataforma $administrador): string
    {
        /** @var SessionGuard $guard */
        $guard = Auth::guard('plataforma');

        return $guard->hashPasswordForCookie($administrador->getAuthPassword());
    }

    /** Guarda la huella en la sesión: al iniciar sesión y al cambiar la propia contraseña (esa sesión sigue válida). */
    public static function recordar(Request $request, AdministradorPlataforma $administrador): void
    {
        $request->session()->put(self::CLAVE_HUELLA, self::huella($administrador));
    }
}
