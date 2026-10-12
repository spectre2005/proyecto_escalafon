<?php

namespace App\Http\Controllers\Plataforma;

use App\Http\Controllers\Controller;
use App\Models\Plataforma\AdministradorPlataforma;
use App\Support\Plataforma\AuditoriaOperaciones;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Sesión del panel de plataforma (guard «plataforma», cuentas de administradores_plataforma). Igual que el login
 * institucional: sesión con CSRF de Sanctum (statefulApi), límite de intentos por correo e IP y la misma respuesta
 * para cualquier rechazo. No hay registro público: la primera cuenta se crea con plataforma:crear-administrador.
 */
class AuthController extends Controller
{
    private const INTENTOS_MAXIMOS = 5;

    private const BLOQUEO_SEGUNDOS = 60;

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

    public function logout(Request $request): JsonResponse
    {
        AuditoriaOperaciones::registrar('sesion.cierre', $request, Auth::guard('plataforma')->id());
        $this->cerrar($request);

        return response()->json(['message' => 'Sesión cerrada.']);
    }

    private function sesion(AdministradorPlataforma $administrador): JsonResponse
    {
        return response()->json(['data' => ['administrador' => $administrador->only(['id', 'nombre', 'email'])]]);
    }

    private function cerrar(Request $request): void
    {
        Auth::guard('plataforma')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
