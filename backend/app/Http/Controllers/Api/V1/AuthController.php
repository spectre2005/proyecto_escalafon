<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Plataforma\Institucion;
use App\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
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
        // Huella de la contraseña desde el inicio de la sesión, con la misma clave y cálculo que AuthenticateSession
        // de Sanctum (que solo la guarda al terminar una petición que ya traía usuario). Así, si la contraseña cambia
        // (p. ej. la restablece la plataforma), también se cierra una sesión que solo llegó a iniciarse.
        $guard = Auth::guard('web');
        $request->session()->put('password_hash_web', $guard->hashPasswordForCookie($request->user()->getAuthPassword()));
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

    /**
     * Cambio de la propia contraseña, con la actual. Quita la marca de contraseña temporal (debe_cambiar_password), que
     * bloquea el resto del sistema (ExigeCambioPassword). La nueva sigue Password::defaults() y no puede ser la misma.
     */
    public function cambiarPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'password_actual' => 'required|string|max:255',
            'password' => ['required', 'string', 'max:255', 'confirmed', Password::defaults()],
        ], [
            'password.confirmed' => 'Las contraseñas nuevas no coinciden.',
            // Política de Password::defaults() (en producción, más estricta): mensajes propios, no claves sin traducir.
            'password.min' => 'La nueva contraseña debe tener al menos :min caracteres.',
            'password.password.letters' => 'La nueva contraseña debe tener letras.',
            'password.password.mixed' => 'La nueva contraseña debe tener mayúsculas y minúsculas.',
            'password.password.numbers' => 'La nueva contraseña debe tener números.',
        ]);
        /** @var Usuario $usuario */
        $usuario = $request->user();
        if (! Hash::check($data['password_actual'], $usuario->password)) {
            throw ValidationException::withMessages(['password_actual' => 'La contraseña actual no es correcta.']);
        }
        if (Hash::check($data['password'], $usuario->password)) {
            throw ValidationException::withMessages(['password' => 'La nueva contraseña debe ser distinta de la actual.']);
        }
        $usuario->forceFill(['password' => $data['password'], 'debe_cambiar_password' => false])->save();
        // La sesión sigue abierta con un identificador nuevo.
        $request->session()->regenerate();

        return $this->sesion($usuario);
    }

    private function sesion(Usuario $usuario): JsonResponse
    {
        return response()->json(['data' => [
            'usuario' => $usuario->only(['id', 'nombres', 'apellido_paterno', 'apellido_materno', 'email_institucional']),
            'permisos' => $usuario->permisos(),
            've_datos_sensibles' => $usuario->veDatosSensibles(),
            'debe_cambiar_password' => (bool) $usuario->debe_cambiar_password,
            'aviso_servicio' => $this->avisoServicio($usuario),
        ]]);
    }

    /**
     * Aviso del tiempo de uso, solo para quien administra la institución (por subdominio): por vencer (desde
     * plataforma.aviso_vencimiento_dias antes) o vencido en días de gracia. El resto del personal no lo ve.
     *
     * @return array<string, mixed>|null
     */
    private function avisoServicio(Usuario $usuario): ?array
    {
        if (! app()->bound(Institucion::class) || ! $usuario->tienePermiso('sistema.administrar')) {
            return null;
        }
        $servicio = app(Institucion::class)->servicio();

        return match (true) {
            $servicio['estado'] === 'en_gracia' => ['tipo' => 'en_gracia', 'vence_el' => $servicio['vence_el'], 'gracia_hasta' => $servicio['gracia_hasta']],
            $servicio['estado'] === 'vigente' && $servicio['dias_restantes'] <= (int) config('plataforma.aviso_vencimiento_dias') => ['tipo' => 'por_vencer', 'vence_el' => $servicio['vence_el'], 'dias_restantes' => $servicio['dias_restantes']],
            default => null,
        };
    }

    private function cerrar(Request $request): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
