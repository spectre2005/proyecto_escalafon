<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Rol;
use App\Models\Usuario;
use App\Models\UsuarioRol;
use App\Support\AuditoriaInstitucional;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccesoPersonalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $datos = $request->validate(['q' => 'nullable|string|max:120', 'page' => 'sometimes|integer|min:1']);
        $consulta = Usuario::query()->with(['usuarioRolesPorUsuario' => fn ($q) => $q->whereHas('rol', fn ($r) => $r->where('codigo', 'asistente_legajo'))->orderByDesc('vigente_desde')]);
        if (! empty($datos['q'])) {
            $q = '%'.mb_strtolower(trim($datos['q'])).'%';
            $consulta->where(fn ($c) => $c->whereRaw("LOWER(nombres || ' ' || apellido_paterno || ' ' || COALESCE(apellido_materno, '')) LIKE ?", [$q])->orWhere('numero_doc_identidad', 'like', $q)->orWhereRaw('LOWER(email_institucional) LIKE ?', [$q]));
        }
        $pagina = $consulta->orderBy('apellido_paterno')->orderBy('id')->paginate(20, ['id', 'nombres', 'apellido_paterno', 'apellido_materno', 'email_institucional', 'activo']);

        return response()->json($pagina);
    }

    public function conceder(Request $request, string $usuario): JsonResponse
    {
        $datos = $this->validar($request, ['vigente_desde' => 'required|date_format:Y-m-d|after_or_equal:'.today('America/Lima')->toDateString(), 'vigente_hasta' => 'nullable|date_format:Y-m-d|after_or_equal:vigente_desde', 'motivo' => 'required|string|max:1000']);

        return DB::transaction(function () use ($request, $usuario, $datos): JsonResponse {
            $persona = $this->persona($usuario);
            if (! filter_var($persona->email_institucional, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages(['email_institucional' => 'Registra un correo institucional válido antes de conceder acceso.']);
            }
            $rol = Rol::query()->firstOrCreate(['codigo' => 'asistente_legajo'], ['nombre' => 'Asistente de legajos', 'activo' => true, 've_datos_sensibles' => false]);
            abort_unless($rol->activo && ! $rol->ve_datos_sensibles, 409, 'Revisa la configuración del rol de asistente antes de conceder acceso.');
            $asignaciones = UsuarioRol::query()->where('usuario_id', $usuario)->where('rol_id', $rol->id)
                ->where(fn ($q) => $q->whereNull('vigente_hasta')->orWhereDate('vigente_hasta', '>=', $datos['vigente_desde']));
            if (! empty($datos['vigente_hasta'])) {
                $asignaciones->whereDate('vigente_desde', '<=', $datos['vigente_hasta']);
            }
            abort_if($asignaciones->exists(), 409, 'Ya existe una asignación que coincide con estas fechas.');
            $clave = null;
            $estabaActiva = $persona->activo;
            if (! $estabaActiva || $persona->permisos() === []) {
                $clave = Str::password(16, symbols: false);
                $persona->forceFill(['password' => $clave, 'debe_cambiar_password' => true, 'activo' => true])->save();
            }
            if (! $estabaActiva) {
                AuditoriaInstitucional::registrar('usuarios', $persona->id, 'acceso.cuenta_habilitada', $request, $usuario, ['activo' => false], ['activo' => true, 'debe_cambiar_password' => true, 'motivo' => $datos['motivo']]);
            }
            $registro = UsuarioRol::query()->create(['usuario_id' => $usuario, 'rol_id' => $rol->id, 'ambito_tipo_personal' => 'ambos', 'vigente_desde' => $datos['vigente_desde'], 'vigente_hasta' => $datos['vigente_hasta'] ?? null, 'asignado_por' => $request->user()->id]);
            AuditoriaInstitucional::registrar('usuario_roles', $registro->id, 'acceso.conceder', $request, $usuario, null, [...$registro->getAttributes(), 'motivo' => $datos['motivo']]);

            return response()->json(['data' => $registro, 'clave_temporal' => $clave], 201);
        });
    }

    public function revocar(Request $request, string $usuario, string $asignacion): JsonResponse
    {
        $datos = $this->validar($request, ['motivo' => 'required|string|max:1000']);

        return DB::transaction(function () use ($request, $usuario, $asignacion, $datos): JsonResponse {
            $this->persona($usuario);
            $registro = UsuarioRol::query()->where('usuario_id', $usuario)->whereHas('rol', fn ($q) => $q->where('codigo', 'asistente_legajo'))->lockForUpdate()->findOrFail($asignacion);
            abort_if($registro->vigente_hasta && $registro->vigente_hasta->isBefore(today('America/Lima')), 409, 'El acceso ya terminó.');
            $antes = $registro->getAttributes();
            $registro->update(['vigente_hasta' => today('America/Lima')->subDay()->toDateString()]);
            AuditoriaInstitucional::registrar('usuario_roles', $registro->id, 'acceso.revocar', $request, $usuario, $antes, [...$registro->getAttributes(), 'motivo' => $datos['motivo']]);

            return response()->json(['data' => $registro]);
        });
    }

    public function restablecer(Request $request, string $usuario): JsonResponse
    {
        $datos = $this->validar($request, ['motivo' => 'required|string|max:1000']);

        return DB::transaction(function () use ($request, $usuario, $datos): JsonResponse {
            $persona = $this->persona($usuario);
            abort_unless($persona->activo && $persona->rolesVigentes()->contains('codigo', 'asistente_legajo'), 409, 'La persona no tiene un acceso de asistente vigente.');
            $clave = Str::password(16, symbols: false);
            $persona->forceFill(['password' => $clave, 'debe_cambiar_password' => true])->save();
            AuditoriaInstitucional::registrar('usuarios', $persona->id, 'acceso.restablecer', $request, $usuario, null, ['motivo' => $datos['motivo'], 'debe_cambiar_password' => true]);

            return response()->json(['clave_temporal' => $clave]);
        });
    }

    private function persona(string $id): Usuario
    {
        $persona = Usuario::query()->lockForUpdate()->findOrFail($id);
        abort_if($persona->usuarioRolesPorUsuario()->whereHas('rol', fn ($q) => $q->where('codigo', 'ADMIN'))->exists(), 409, 'Las cuentas de administración se gestionan desde el panel de plataforma.');

        return $persona;
    }

    private function validar(Request $request, array $reglas): array
    {
        $extra = array_diff(array_keys($request->all()), array_keys($reglas));
        if ($extra !== []) {
            throw ValidationException::withMessages(array_fill_keys($extra, 'Campo no permitido.'));
        }

        return $request->validate($reglas);
    }
}
