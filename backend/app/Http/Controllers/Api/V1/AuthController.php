<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private const INTENTOS_MAXIMOS = 5;

    private const BLOQUEO_SEGUNDOS = 60;

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => 'required|email|max:255', 'password' => 'required|string|max:255']);
        $email = mb_strtolower(trim($data['email']));
        $clave = 'login:'.$email.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($clave, self::INTENTOS_MAXIMOS)) {
            $segundos = RateLimiter::availableIn($clave);

            return response()->json(['message' => "Demasiados intentos. Intenta de nuevo en {$segundos} segundos."], 429)
                ->header('Retry-After', (string) $segundos);
        }

        // Cualquier rechazo (clave mala, cuenta inactiva o sin permisos) responde igual,
        // para no confirmar si la contraseña era correcta.
        if (! Auth::guard('web')->attempt(['email_institucional' => $email, 'password' => $data['password'], 'activo' => true])
            || $request->user()->permisos() === []) {
            Auth::guard('web')->logout();
            RateLimiter::hit($clave, self::BLOQUEO_SEGUNDOS);
            throw ValidationException::withMessages(['email' => 'Correo o contraseña incorrectos.']);
        }

        RateLimiter::clear($clave);
        $request->session()->regenerate();
        // Sin tocar updated_at: lo usa la edición del legajo para detectar cambios ajenos.
        Usuario::withoutTimestamps(fn () => $request->user()->forceFill(['ultimo_acceso' => now()])->saveQuietly());

        return $this->sesion($request->user());
    }

    public function me(Request $request): JsonResponse
    {
        if ($request->user()->permisos() === []) {
            $this->cerrar($request);
            abort(401, 'La sesión ya no es válida.');
        }

        return $this->sesion($request->user());
    }

    public function logout(Request $request): JsonResponse
    {
        $this->cerrar($request);

        return response()->json(['message' => 'Sesión cerrada.']);
    }

    private function sesion(Usuario $usuario): JsonResponse
    {
        return response()->json(['data' => [
            'usuario' => $usuario->only(['id', 'nombres', 'apellido_paterno', 'apellido_materno', 'email_institucional']),
            'permisos' => $usuario->permisos(),
            've_datos_sensibles' => $usuario->veDatosSensibles(),
        ]]);
    }

    private function cerrar(Request $request): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
