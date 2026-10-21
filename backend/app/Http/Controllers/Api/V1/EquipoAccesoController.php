<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditoriaEvento;
use App\Models\Rol;
use App\Models\Usuario;
use App\Models\UsuarioRol;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EquipoAccesoController extends Controller
{
    private const PERFILES = ['asistente_legajo' => 'Asistente de legajos', 'suplente_legajo' => 'Suplente de administración'];

    public function index(): JsonResponse
    {
        $registros = $this->asignaciones()->with(['usuario', 'rol', 'asignadoPor'])->orderByDesc('created_at')->get();

        return response()->json(['data' => [
            'asignaciones' => $registros->map(fn (UsuarioRol $registro): array => $this->datos($registro)),
            'perfiles' => collect(self::PERFILES)->map(fn (string $nombre, string $codigo): array => [
                'codigo' => $codigo, 'nombre' => $nombre,
                'permisos' => collect(config('permisos.roles.'.$codigo))->map(fn (string $permiso): string => config('permisos.catalogo')[$permiso])->values(),
            ])->values(),
            'hoy' => today()->toDateString(), 'zona_horaria' => config('app.timezone'),
        ]]);
    }

    public function personas(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => 'required|string|min:3|max:100']);
        $termino = mb_strtolower(trim($data['q']));
        $personas = Usuario::query()->where('activo', true)->whereKeyNot($request->user()->id)
            ->where(function (Builder $query) use ($termino): void {
                foreach (['nombres', 'apellido_paterno', 'numero_doc_identidad', 'email_institucional'] as $campo) {
                    $query->orWhereRaw('LOWER('.$campo.') LIKE ?', ['%'.addcslashes($termino, '%_\\').'%']);
                }
            })->orderBy('apellido_paterno')->limit(20)->get();

        return response()->json(['data' => $personas->map(fn (Usuario $usuario): array => $this->persona($usuario))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validar($request, [
            'usuario_id' => 'nullable|uuid|exists:usuarios,id',
            'nombres' => ['required_without:usuario_id', Rule::prohibitedIf($request->filled('usuario_id')), 'string', 'max:120'],
            'apellido_paterno' => ['required_without:usuario_id', Rule::prohibitedIf($request->filled('usuario_id')), 'string', 'max:80'],
            'numero_doc_identidad' => ['required_without:usuario_id', Rule::prohibitedIf($request->filled('usuario_id')), 'digits:8', 'unique:usuarios,numero_doc_identidad'],
            'email' => ['required_without:usuario_id', Rule::prohibitedIf($request->filled('usuario_id')), 'email', 'max:255'],
            'perfil' => ['required', Rule::in(array_keys(self::PERFILES))],
            'vigente_desde' => 'required|date_format:Y-m-d|after_or_equal:today',
            'vigente_hasta' => 'nullable|required_if:perfil,suplente_legajo|date_format:Y-m-d|after_or_equal:vigente_desde',
            'motivo' => 'required|string|min:5|max:500',
        ]);

        return DB::transaction(function () use ($request, $data): JsonResponse {
            $clave = null;
            if (! empty($data['usuario_id'])) {
                $usuario = Usuario::query()->lockForUpdate()->findOrFail($data['usuario_id']);
            } else {
                $email = mb_strtolower($data['email']);
                if (Usuario::query()->whereRaw('LOWER(email_institucional) = ?', [$email])->exists()) {
                    throw ValidationException::withMessages(['email' => 'Ya existe una persona con este correo. Selecciónala en la búsqueda.']);
                }
                $clave = Str::password(16, symbols: false);
                $usuario = Usuario::query()->create([
                    'nombres' => $data['nombres'], 'apellido_paterno' => $data['apellido_paterno'],
                    'tipo_doc_identidad' => 'dni', 'numero_doc_identidad' => $data['numero_doc_identidad'],
                    'email_institucional' => $email, 'password' => $clave,
                    'tipo_personal' => 'administrativo', 'regimen_laboral' => 'No aplica', 'activo' => true,
                ]);
                $usuario->forceFill(['debe_cambiar_password' => true, 'password_temporal_vence_en' => now()->addHours(48)])->save();
            }
            $this->comprobarPersona($usuario, $request);
            abort_if($this->asignaciones()->where('usuario_id', $usuario->id)->whereNull('revocado_en')
                ->where(fn (Builder $query): Builder => $query->whereNull('vigente_hasta')->orWhere('vigente_hasta', '>=', today()->toDateString()))->exists(),
                409, 'La persona ya tiene un acceso vigente o programado. Revócalo antes de cambiar de perfil.');
            $rol = Rol::query()->firstOrCreate(['codigo' => $data['perfil']], [
                'nombre' => self::PERFILES[$data['perfil']], 've_datos_sensibles' => false, 'activo' => true,
            ]);
            abort_unless($rol->activo && ! $rol->ve_datos_sensibles, 409, 'El perfil tiene una configuración incompatible. Revisa su configuración antes de asignarlo.');
            $asignacion = UsuarioRol::query()->create([
                'usuario_id' => $usuario->id, 'rol_id' => $rol->id, 'ambito_tipo_personal' => 'ambos',
                'vigente_desde' => $data['vigente_desde'], 'vigente_hasta' => $data['vigente_hasta'] ?? null,
                'asignado_por' => $request->user()->id,
            ]);
            $asignacion->forceFill(['motivo' => $data['motivo']])->save();
            $this->auditar($asignacion, $request, 'equipo.acceso_otorgado', null, $data['motivo']);

            return response()->json(['data' => $this->datos($asignacion->load(['usuario', 'rol', 'asignadoPor'])),
                'credenciales' => $clave ? ['email' => $usuario->email_institucional, 'password_temporal' => $clave] : null], 201);
        });
    }

    public function update(Request $request, string $asignacion): JsonResponse
    {
        $data = $this->validar($request, ['vigente_hasta' => 'nullable|date_format:Y-m-d|after_or_equal:today', 'motivo' => 'required|string|min:5|max:500']);
        abort_unless(array_key_exists('vigente_hasta', $data), 422, 'Indica la fecha de término.');

        return DB::transaction(function () use ($request, $asignacion, $data): JsonResponse {
            $registro = $this->bloquear($asignacion, $request);
            abort_if($registro->revocado_en !== null, 409, 'El acceso está revocado. Otorga uno nuevo.');
            abort_if($registro->rol->codigo === 'suplente_legajo' && empty($data['vigente_hasta']), 422, 'El suplente requiere una fecha de término.');
            abort_if(! empty($data['vigente_hasta']) && $data['vigente_hasta'] < $registro->vigente_desde->toDateString(), 422, 'La fecha de término no puede ser anterior al inicio.');
            abort_if($this->asignaciones()->where('usuario_id', $registro->usuario_id)->whereKeyNot($registro->id)->whereNull('revocado_en')
                ->where(fn (Builder $query): Builder => $query->whereNull('vigente_hasta')->orWhere('vigente_hasta', '>=', today()->toDateString()))->exists(),
                409, 'La persona tiene otro acceso vigente o programado.');
            $antes = $this->datos($registro);
            $registro->forceFill(['vigente_hasta' => $data['vigente_hasta'], 'motivo' => $data['motivo']])->save();
            $this->auditar($registro, $request, 'equipo.acceso_renovado', $antes, $data['motivo']);

            return response()->json(['data' => $this->datos($registro)]);
        });
    }

    public function revocar(Request $request, string $asignacion): JsonResponse
    {
        $data = $this->validar($request, ['motivo' => 'required|string|min:5|max:500']);

        return DB::transaction(function () use ($request, $asignacion, $data): JsonResponse {
            $registro = $this->bloquear($asignacion, $request);
            abort_if($registro->revocado_en !== null, 409, 'El acceso ya está revocado.');
            $antes = $this->datos($registro);
            $registro->forceFill(['revocado_en' => now(), 'revocado_por' => $request->user()->id, 'motivo' => $data['motivo']])->save();
            $this->auditar($registro, $request, 'equipo.acceso_revocado', $antes, $data['motivo']);

            return response()->json(['data' => $this->datos($registro)]);
        });
    }

    public function restablecer(Request $request, string $asignacion): JsonResponse
    {
        $data = $this->validar($request, ['motivo' => 'required|string|min:5|max:500']);

        return DB::transaction(function () use ($request, $asignacion, $data): JsonResponse {
            $registro = $this->bloquear($asignacion, $request);
            abort_if($registro->revocado_en || ($registro->vigente_hasta && $registro->vigente_hasta->lt(today())), 409, 'Renueva el acceso antes de restablecer la contraseña.');
            $clave = Str::password(16, symbols: false);
            $registro->usuario->forceFill(['password' => $clave, 'debe_cambiar_password' => true, 'password_temporal_vence_en' => now()->addHours(48)])->save();
            $this->auditar($registro, $request, 'equipo.clave_restablecida', null, $data['motivo']);

            return response()->json(['data' => ['email' => $registro->usuario->email_institucional, 'password_temporal' => $clave]]);
        });
    }

    private function asignaciones(): Builder
    {
        return UsuarioRol::query()->whereHas('rol', fn (Builder $query): Builder => $query->whereIn('codigo', array_keys(self::PERFILES)));
    }

    private function bloquear(string $id, Request $request): UsuarioRol
    {
        $registro = $this->asignaciones()->findOrFail($id);
        $usuario = Usuario::query()->lockForUpdate()->findOrFail($registro->usuario_id);
        $this->comprobarPersona($usuario, $request);

        return $this->asignaciones()->with(['usuario', 'rol', 'asignadoPor'])->lockForUpdate()->findOrFail($id);
    }

    private function comprobarPersona(Usuario $usuario, Request $request): void
    {
        abort_if($usuario->id === $request->user()->id, 409, 'No puedes gestionar tu propio acceso.');
        abort_unless($usuario->activo, 409, 'La cuenta está desactivada.');
        abort_if($usuario->usuarioRolesPorUsuario()->whereNull('revocado_en')
            ->whereHas('rol', fn (Builder $query): Builder => $query->whereNotIn('codigo', array_keys(self::PERFILES)))->exists(),
            409, 'La persona tiene otros roles. Este panel solo gestiona cuentas de asistentes y suplentes.');
    }

    private function validar(Request $request, array $reglas): array
    {
        $desconocidos = array_diff(array_keys($request->all()), array_keys($reglas));
        if ($desconocidos !== []) {
            throw ValidationException::withMessages(array_fill_keys($desconocidos, 'Campo no permitido.'));
        }

        return $request->validate($reglas);
    }

    private function persona(Usuario $usuario): array
    {
        return ['id' => $usuario->id, 'nombre' => trim($usuario->nombres.' '.$usuario->apellido_paterno.' '.$usuario->apellido_materno),
            'email' => $usuario->email_institucional, 'documento' => $usuario->numero_doc_identidad, 'activo' => $usuario->activo];
    }

    private function datos(UsuarioRol $registro): array
    {
        $estado = match (true) {
            $registro->revocado_en !== null => 'revocado',
            ! $registro->usuario->activo || ! $registro->rol->activo => 'inactivo',
            $registro->vigente_desde->gt(today()) => 'programado',
            $registro->vigente_hasta !== null && $registro->vigente_hasta->lt(today()) => 'vencido',
            default => 'vigente',
        };

        return ['id' => $registro->id, 'persona' => $this->persona($registro->usuario), 'perfil' => $registro->rol->codigo,
            'nombre_perfil' => self::PERFILES[$registro->rol->codigo], 'estado' => $estado,
            'vigente_desde' => $registro->vigente_desde->toDateString(), 'vigente_hasta' => $registro->vigente_hasta?->toDateString(),
            'motivo' => $registro->motivo, 'revocado_en' => $registro->revocado_en?->toIso8601String(),
            'asignado_por' => $registro->asignadoPor?->nombres, 'debe_cambiar_password' => $registro->usuario->debe_cambiar_password];
    }

    private function auditar(UsuarioRol $registro, Request $request, string $accion, ?array $antes, string $motivo): void
    {
        $datos = ['fecha_evento' => now()->format('Y-m-d H:i:s'), 'actor_usuario_id' => $request->user()->id,
            'usuario_titular_id' => $registro->usuario_id, 'accion' => $accion, 'entidad' => 'equipo_accesos', 'entidad_id' => $registro->id,
            'datos_antes' => $antes === null ? null : json_encode($antes, JSON_THROW_ON_ERROR),
            'datos_despues' => json_encode(['acceso' => $this->datos($registro), 'motivo' => $motivo], JSON_THROW_ON_ERROR),
            'ip_origen' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 300), 'request_id' => (string) Str::uuid(),
            'hash_previo' => AuditoriaEvento::query()->where('entidad', 'equipo_accesos')->where('entidad_id', $registro->id)->orderByDesc('id')->value('hash_registro')];
        AuditoriaEvento::query()->create([...$datos, 'hash_registro' => hash('sha256', json_encode($datos, JSON_THROW_ON_ERROR))]);
    }
}
