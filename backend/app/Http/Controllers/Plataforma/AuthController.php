<?php

namespace App\Http\Controllers\Plataforma;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SesionPlataformaValida;
use App\Models\Plataforma\AdministradorPlataforma;
use App\Models\Plataforma\AuditoriaPlataforma;
use App\Support\Plataforma\AuditoriaOperaciones;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Sesión del panel de plataforma (guard «plataforma», cuentas de administradores_plataforma). Igual que el login
 * institucional: sesión con CSRF de Sanctum (statefulApi), límite de intentos por correo e IP y la misma respuesta
 * para cualquier rechazo; pero con su propia cookie de sesión (SesionPlataforma). No hay registro público: la primera cuenta se crea con plataforma:crear-administrador.
 */
class AuthController extends Controller
{
    private const INTENTOS_MAXIMOS = 5;

    private const BLOQUEO_SEGUNDOS = 60;

    /** Token CSRF de la sesión de plataforma, para la cabecera X-CSRF-TOKEN (su cookie XSRF-TOKEN no es legible desde el SPA). */
    public function csrf(Request $request): JsonResponse
    {
        return response()->json(['data' => ['token' => $request->session()->token()]]);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => 'required|email|max:255', 'password' => 'required|string|max:255']);
        $email = mb_strtolower(trim($data['email']));
        $clave = 'plataforma-login:'.$email.'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($clave, self::INTENTOS_MAXIMOS)) {
            $segundos = RateLimiter::availableIn($clave);

            return response()->json(['message' => "Demasiados intentos. Intenta de nuevo en {$segundos} segundos."], 429)->header('Retry-After', (string) $segundos);
        }
        if (! Auth::guard('plataforma')->attempt(['email' => $email, 'password' => $data['password'], 'activo' => true])) {
            RateLimiter::hit($clave, self::BLOQUEO_SEGUNDOS);
            throw ValidationException::withMessages(['email' => 'Correo o contraseña incorrectos.']);
        }
        RateLimiter::clear($clave);
        $request->session()->regenerate();
        /** @var AdministradorPlataforma $administrador */
        $administrador = Auth::guard('plataforma')->user();
        $administrador->forceFill(['ultimo_acceso' => now()])->save();
        SesionPlataformaValida::recordar($request, $administrador);
        AuditoriaOperaciones::registrar('sesion.inicio', $request, $administrador->id);

        return $this->sesion($administrador);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var AdministradorPlataforma $administrador */
        $administrador = Auth::guard('plataforma')->user();
        if (! $administrador->activo) {
            $this->cerrar($request);
            abort(401, 'La sesión ya no es válida.');
        }

        return $this->sesion($administrador);
    }

    /**
     * Cambio de la propia contraseña, con la actual. Quita la marca de contraseña temporal. La sesión con la que se cambia
     * sigue válida (se renueva su huella); las demás sesiones de la cuenta se cierran en su siguiente petición.
     */
    public function cambiarPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'password_actual' => 'required|string|max:255',
            'password' => [...AdministradorPlataforma::reglasPassword(), 'confirmed'],
        ], AdministradorPlataforma::mensajesPassword());
        /** @var AdministradorPlataforma $administrador */
        $administrador = Auth::guard('plataforma')->user();
        if (! Hash::check($data['password_actual'], $administrador->password)) {
            throw ValidationException::withMessages(['password_actual' => 'La contraseña actual no es correcta.']);
        }
        if (Hash::check($data['password'], $administrador->password)) {
            throw ValidationException::withMessages(['password' => 'La nueva contraseña debe ser distinta de la actual.']);
        }
        $administrador->forceFill(['password' => $data['password'], 'debe_cambiar_password' => false])->save();
        $request->session()->regenerate();
        SesionPlataformaValida::recordar($request, $administrador);
        AuditoriaOperaciones::registrar('administrador.clave_cambiada', $request, $administrador->id, null, null, $administrador->only(['id', 'email']));

        return $this->sesion($administrador);
    }

    /**
     * Datos de la propia cuenta. El acceso anterior (fecha, IP y navegador) es el inicio de sesión previo al de esta
     * sesión: sirve para notar uno que no se reconoce.
     */
    public function cuenta(): JsonResponse
    {
        /** @var AdministradorPlataforma $administrador */
        $administrador = Auth::guard('plataforma')->user();
        $propias = fn () => AuditoriaPlataforma::query()->where('administrador_id', $administrador->id);
        $anterior = $propias()->where('accion', 'sesion.inicio')->latest('created_at')->skip(1)->first();

        return response()->json(['data' => [
            'cuenta' => [...$administrador->only(['id', 'nombre', 'email']), 'creada_en' => $administrador->created_at?->toIso8601String(),
                'ultimo_acceso' => $administrador->ultimo_acceso?->toIso8601String(),
                'clave_cambiada_en' => $propias()->where('accion', 'administrador.clave_cambiada')->latest('created_at')->value('created_at')?->toIso8601String()],
            'acceso_anterior' => $anterior ? [...$anterior->only(['id', 'ip_origen', 'user_agent']), 'created_at' => $anterior->created_at?->toIso8601String()] : null,
        ]]);
    }

    /** Cambio del propio nombre (el correo es el usuario de acceso: no se cambia aquí). */
    public function actualizarCuenta(Request $request): JsonResponse
    {
        $nombre = trim((string) $request->validate(['nombre' => 'required|string|max:150'], ['nombre.required' => 'Escribe tu nombre.'])['nombre']);
        if ($nombre === '') {
            throw ValidationException::withMessages(['nombre' => 'Escribe tu nombre.']);
        }
        /** @var AdministradorPlataforma $administrador */
        $administrador = Auth::guard('plataforma')->user();
        if ($nombre !== $administrador->nombre) {
            $antes = $administrador->only(['nombre']);
            $administrador->forceFill(['nombre' => $nombre])->save();
            AuditoriaOperaciones::registrar('administrador.nombre_cambiado', $request, $administrador->id, null, $antes, $administrador->only(['id', 'nombre']));
        }

        return $this->sesion($administrador);
    }

    /**
     * Cierra las demás sesiones de la cuenta sin cambiar la contraseña: la vuelve a cifrar (misma contraseña, cifrado
     * nuevo), así cambia su huella y SesionPlataformaValida cierra las otras en su siguiente petición. Esta sigue válida.
     */
    public function cerrarOtrasSesiones(Request $request): JsonResponse
    {
        $data = $request->validate(['password_actual' => 'required|string|max:255'], ['password_actual.required' => 'Escribe tu contraseña actual.']);
        /** @var AdministradorPlataforma $administrador */
        $administrador = Auth::guard('plataforma')->user();
        if (! Hash::check($data['password_actual'], $administrador->password)) {
            throw ValidationException::withMessages(['password_actual' => 'La contraseña actual no es correcta.']);
        }
        $administrador->forceFill(['password' => $data['password_actual']])->save();
        $request->session()->regenerate();
        SesionPlataformaValida::recordar($request, $administrador);
        AuditoriaOperaciones::registrar('sesion.otras_cerradas', $request, $administrador->id);

        return response()->json(['message' => 'Se cerraron tus otras sesiones.']);
    }

    public function logout(Request $request): JsonResponse
    {
        AuditoriaOperaciones::registrar('sesion.cierre', $request, Auth::guard('plataforma')->id());
        $this->cerrar($request);

        return response()->json(['message' => 'Sesión cerrada.']);
    }

    private function sesion(AdministradorPlataforma $administrador): JsonResponse
    {
        return response()->json(['data' => ['administrador' => $administrador->only(['id', 'nombre', 'email']),
            'debe_cambiar_password' => (bool) $administrador->debe_cambiar_password]]);
    }

    private function cerrar(Request $request): void
    {
        Auth::guard('plataforma')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
