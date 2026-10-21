<?php

namespace App\Http\Controllers\Plataforma;

use App\Http\Controllers\Controller;
use App\Models\Plataforma\AdministradorPlataforma;
use App\Support\PasswordTemporal;
use App\Support\Plataforma\AuditoriaOperaciones;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Cuentas de superadministrador desde el panel: listar, crear, restablecer la contraseña, desactivar y reactivar. No se
 * borran (la auditoría las sigue citando).
 * - Crear, restablecer y reactivar dan una contraseña temporal, que se muestra una sola vez, no se guarda en ningún
 *   otro lugar ni se audita, y obliga a cambiarla (SesionPlataformaValida).
 * - Desactivada, ninguna sesión suya pasa (SesionPlataformaValida mira «activo» en cada petición) y no puede entrar.
 *   Reactivar le da una contraseña temporal nueva: su huella cambia, así que ninguna sesión anterior vuelve a valer.
 * - Nadie se desactiva ni se restablece a sí mismo (su contraseña la cambia en «Mi cuenta»), y nunca queda la
 *   plataforma sin un superadministrador activo.
 */
class SuperadministradorController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => AdministradorPlataforma::query()->orderByDesc('activo')->orderBy('nombre')->orderBy('id')->get()
            ->map(fn (AdministradorPlataforma $a): array => [...$a->datos(), 'es_actual' => $a->id === Auth::guard('plataforma')->id()])]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->sinCamposAjenos($request, ['dni', 'nombre', 'email', 'vigencia_horas', 'vence_en']);
        $request->merge(['nombre' => trim((string) $request->input('nombre')), 'email' => mb_strtolower(trim((string) $request->input('email')))]);
        $datos = $request->validate([
            ...PasswordTemporal::reglasPlazo(),
            'dni' => ['nullable', 'string', 'regex:/\A[0-9]{8}\z/', Rule::unique('plataforma.administradores_plataforma', 'dni')],
            'nombre' => 'required|string|min:3|max:150',
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('plataforma.administradores_plataforma', 'email')],
        ], ['email.unique' => 'Ya existe una cuenta con ese correo.']);
        $clave = self::claveTemporal();
        $limite = PasswordTemporal::limite($datos);
        unset($datos['vigencia_horas'], $datos['vence_en']);
        $cuenta = AdministradorPlataforma::query()->create([...$datos, 'password_temporal_vence_en' => $limite, 'password' => $clave, 'activo' => true, 'debe_cambiar_password' => true]);
        AuditoriaOperaciones::registrar('administrador.creado', $request, Auth::guard('plataforma')->id(), null, null, $cuenta->only(['id', 'nombre', 'email']), 'Creado desde el panel.');

        return $this->conClave($cuenta, $clave, 201);
    }

    public function restablecer(Request $request, string $cuenta): JsonResponse
    {
        $this->sinCamposAjenos($request, ['vigencia_horas', 'vence_en']);
        $datos = $request->validate(PasswordTemporal::reglasPlazo());
        $registro = $this->otra($cuenta, 'Tu propia contraseña se cambia en «Mi cuenta».');
        abort_unless($registro->activo, 409, 'La cuenta está desactivada: reactívala para darle una contraseña temporal.');
        $clave = self::claveTemporal();
        $registro->forceFill(['password' => $clave, 'debe_cambiar_password' => true, 'password_temporal_vence_en' => PasswordTemporal::limite($datos)])->save();
        AuditoriaOperaciones::registrar('administrador.clave_restablecida', $request, Auth::guard('plataforma')->id(), null, null, $registro->only(['id', 'email']));

        return $this->conClave($registro, $clave);
    }

    public function desactivar(Request $request, string $cuenta): JsonResponse
    {
        $this->sinCamposAjenos($request, []);
        $registro = DB::connection('plataforma')->transaction(function () use ($cuenta): AdministradorPlataforma {
            // Nunca queda la plataforma sin un superadministrador activo: quien desactiva sigue activo (comprobado con las
            // cuentas activas bloqueadas, así dos que se desactivan a la vez no se anulan entre sí) y no puede ser la
            // misma cuenta. El último activo, por tanto, solo podría desactivarse a sí mismo, y eso no se permite.
            $activas = AdministradorPlataforma::query()->where('activo', true)->lockForUpdate()->get();
            $registro = $this->otra($cuenta, 'No puedes desactivar tu propia cuenta: la plataforma no puede quedar sin un superadministrador activo.');
            abort_unless($registro->activo, 409, 'La cuenta ya está desactivada.');
            abort_unless($activas->contains('id', Auth::guard('plataforma')->id()), 401, 'La sesión ya no es válida.');
            $registro->forceFill(['activo' => false])->save();

            return $registro;
        });
        AuditoriaOperaciones::registrar('administrador.desactivado', $request, Auth::guard('plataforma')->id(), null, ['activo' => true], $registro->only(['id', 'email', 'activo']));

        return response()->json(['data' => $registro->datos()]);
    }

    public function reactivar(Request $request, string $cuenta): JsonResponse
    {
        $this->sinCamposAjenos($request, ['vigencia_horas', 'vence_en']);
        $datos = $request->validate(PasswordTemporal::reglasPlazo());
        $registro = $this->otra($cuenta, 'Tu cuenta ya está activa.');
        abort_if($registro->activo, 409, 'La cuenta ya está activa.');
        $clave = self::claveTemporal();
        $registro->forceFill(['activo' => true, 'password' => $clave, 'debe_cambiar_password' => true, 'password_temporal_vence_en' => PasswordTemporal::limite($datos)])->save();
        AuditoriaOperaciones::registrar('administrador.reactivado', $request, Auth::guard('plataforma')->id(), null, ['activo' => false], $registro->only(['id', 'email', 'activo']));

        return $this->conClave($registro, $clave);
    }

    /** Otra cuenta de superadministrador (nunca la propia). */
    private function otra(string $id, string $siEsLaPropia): AdministradorPlataforma
    {
        $registro = AdministradorPlataforma::query()->findOrFail($id);
        abort_if($registro->id === Auth::guard('plataforma')->id(), 409, $siEsLaPropia);

        return $registro;
    }

    /** Contraseña temporal que cumple las reglas de superadministrador (letras y números, 16 caracteres). */
    private static function claveTemporal(): string
    {
        do {
            $clave = Str::password(16, symbols: false);
        } while (! preg_match('/[A-Za-z]/', $clave) || ! preg_match('/[0-9]/', $clave));

        return $clave;
    }

    private function conClave(AdministradorPlataforma $cuenta, string $clave, int $estado = 200): JsonResponse
    {
        return response()->json(['data' => [...$cuenta->datos(), 'clave_temporal' => $clave]], $estado)->header('Cache-Control', 'no-store');
    }

    private function sinCamposAjenos(Request $request, array $permitidos): void
    {
        $ajenos = array_diff(array_keys($request->all()), $permitidos);
        if ($ajenos !== []) {
            throw ValidationException::withMessages(array_fill_keys($ajenos, 'Campo no permitido.'));
        }
    }
}
