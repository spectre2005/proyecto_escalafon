<?php

namespace App\Http\Controllers\Plataforma;

use App\Http\Controllers\Controller;
use App\Models\Plataforma\AdministradorPlataforma;
use App\Support\Plataforma\AuditoriaOperaciones;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class CuentasPlataformaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $datos = $request->validate(['page' => 'sometimes|integer|min:1', 'q' => 'nullable|string|max:120']);
        $q = mb_strtolower(trim($datos['q'] ?? ''));

        return response()->json(AdministradorPlataforma::query()->when($q !== '', fn ($c) => $c->where(fn ($f) => $f->whereRaw('LOWER(nombre) LIKE ?', ['%'.$q.'%'])->orWhereRaw('LOWER(email) LIKE ?', ['%'.$q.'%'])))
            ->orderBy('nombre')->orderBy('id')->paginate(20, ['id', 'nombre', 'email', 'activo', 'ultimo_acceso', 'created_at']));
    }

    public function store(Request $request): JsonResponse
    {
        $datos = $this->validar($request, ['nombre' => 'required|string|max:255', 'email' => 'required|email|max:255',
            'password' => ['required', 'string', 'max:255', 'confirmed', Password::defaults()], 'password_confirmation' => 'required|string|max:255', 'motivo' => 'required|string|min:5|max:1000']);
        $datos['email'] = mb_strtolower(trim($datos['email']));
        $cuenta = DB::connection('plataforma')->transaction(function () use ($datos, $request): AdministradorPlataforma {
            $this->bloquear();
            if (AdministradorPlataforma::query()->whereRaw('LOWER(email) = ?', [$datos['email']])->exists()) {
                throw ValidationException::withMessages(['email' => 'Este correo ya tiene una cuenta de plataforma.']);
            }
            $cuenta = AdministradorPlataforma::query()->create(['nombre' => trim($datos['nombre']), 'email' => $datos['email'], 'password' => $datos['password'], 'activo' => true]);
            AuditoriaOperaciones::registrar('cuenta.creada', $request, $request->user('plataforma')->id, despues: $cuenta->only(['id', 'nombre', 'email', 'activo']), motivo: $datos['motivo']);

            return $cuenta;
        });

        return response()->json(['data' => $cuenta], 201);
    }

    public function estado(Request $request, string $cuenta): JsonResponse
    {
        $datos = $this->validar($request, ['activo' => 'required|boolean', 'motivo' => 'required|string|min:5|max:1000']);
        $modelo = DB::connection('plataforma')->transaction(function () use ($request, $cuenta, $datos): AdministradorPlataforma {
            $this->bloquear();
            $modelo = AdministradorPlataforma::query()->lockForUpdate()->findOrFail($cuenta);
            $nuevo = (bool) $datos['activo'];
            abort_if($modelo->activo === $nuevo, 409, 'La cuenta ya tiene ese estado.');
            abort_if(! $nuevo && AdministradorPlataforma::query()->where('activo', true)->count() <= 1, 409, 'Debe mantenerse una cuenta de superadministración activa.');
            abort_if(! $nuevo && $modelo->id === $request->user('plataforma')->id, 409, 'Desactiva esta cuenta desde otra cuenta de superadministración activa.');
            $antes = $modelo->only(['id', 'nombre', 'email', 'activo']);
            $modelo->update(['activo' => $nuevo]);
            AuditoriaOperaciones::registrar($nuevo ? 'cuenta.activada' : 'cuenta.desactivada', $request, $request->user('plataforma')->id, antes: $antes, despues: $modelo->only(['id', 'nombre', 'email', 'activo']), motivo: $datos['motivo']);

            return $modelo;
        });

        return response()->json(['data' => $modelo]);
    }

    public function restablecer(Request $request, string $cuenta): JsonResponse
    {
        $datos = $this->validar($request, ['password' => ['required', 'string', 'max:255', 'confirmed', Password::defaults()], 'password_confirmation' => 'required|string|max:255', 'motivo' => 'required|string|min:5|max:1000']);
        DB::connection('plataforma')->transaction(function () use ($request, $cuenta, $datos): void {
            $this->bloquear();
            $modelo = AdministradorPlataforma::query()->lockForUpdate()->findOrFail($cuenta);
            abort_if($modelo->id === $request->user('plataforma')->id, 409, 'Para tu propia contraseña usa Mi cuenta y confirma la contraseña actual.');
            $modelo->update(['password' => $datos['password']]);
            AuditoriaOperaciones::registrar('cuenta.password_restablecida', $request, $request->user('plataforma')->id, despues: ['cuenta_id' => $modelo->id, 'email' => $modelo->email], motivo: $datos['motivo']);
        });

        return response()->json(['message' => 'Contraseña restablecida. Las sesiones anteriores dejan de ser válidas.']);
    }

    private function bloquear(): void
    {
        $db = DB::connection('plataforma');
        if ($db->getDriverName() === 'pgsql') {
            $db->statement("SELECT pg_advisory_xact_lock(hashtext('cuentas_plataforma'))");
        }
        AdministradorPlataforma::query()->orderBy('id')->lockForUpdate()->get(['id']);
    }

    private function validar(Request $request, array $reglas): array
    {
        abort_if(array_diff(array_keys($request->all()), array_keys($reglas)) !== [], 422, 'Campo no permitido.');

        return $request->validate($reglas);
    }
}
