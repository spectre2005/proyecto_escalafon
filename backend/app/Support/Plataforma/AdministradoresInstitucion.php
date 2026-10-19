<?php

namespace App\Support\Plataforma;

use App\Models\Plataforma\Institucion;
use App\Models\Rol;
use App\Models\Usuario;
use App\Models\UsuarioRol;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Administradores de una institución vistos desde el panel de plataforma: solo las cuentas con permiso de
 * administración (sistema.administrar), con nombre, correo y estado; ningún dato de personas, legajos ni documentos.
 * Se crean con rol ADMIN y contraseña temporal (debe_cambiar_password), igual que el primero del aprovisionamiento.
 * La contraseña temporal solo se devuelve a quien la pidió: no se guarda en la plataforma ni se audita.
 */
final class AdministradoresInstitucion
{
    private const CONEXION = 'institucion_administracion';

    /** Ejecuta $accion con la base de la institución como conexión por defecto (solo la base; nada más cambia). */
    public static function enBase(Institucion $institucion, Closure $accion): mixed
    {
        $anterior = DB::getDefaultConnection();
        config(['database.connections.'.self::CONEXION => [...(array) config('database.connections.'.$anterior), 'database' => $institucion->base_datos]]);
        DB::setDefaultConnection(self::CONEXION);
        try {
            return DB::transaction(fn () => $accion());
        } finally {
            DB::setDefaultConnection($anterior);
            DB::purge(self::CONEXION);
        }
    }

    /** @return list<array<string, mixed>> en la base actual */
    public static function listar(): array
    {
        return Usuario::query()->whereHas('usuarioRolesPorUsuario')->orderBy('apellido_paterno')->orderBy('nombres')->get()
            ->filter(fn (Usuario $usuario): bool => self::esAdministrador($usuario))
            ->map(fn (Usuario $usuario): array => self::datos($usuario))->values()->all();
    }

    /**
     * Nuevo administrador en la base actual, con rol ADMIN y contraseña temporal.
     *
     * @param  array{nombres: string, apellido_paterno: string, email: string}  $datos
     * @return array{0: Usuario, 1: string} el usuario y su contraseña temporal
     */
    public static function crear(array $datos): array
    {
        $email = mb_strtolower(trim($datos['email']));
        if (Usuario::query()->where('email_institucional', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'Ya existe una cuenta con ese correo en la institución.']);
        }
        $clave = self::claveTemporal();
        $admin = Usuario::query()->create([
            'email_institucional' => $email, 'tipo_doc_identidad' => 'sistema', 'numero_doc_identidad' => 'ADMIN-'.strtoupper(Str::random(8)),
            'nombres' => trim($datos['nombres']), 'apellido_paterno' => trim($datos['apellido_paterno']), 'tipo_personal' => 'administrativo',
            'regimen_laboral' => 'No aplica', 'password' => $clave, 'activo' => true,
        ]);
        // Contraseña temporal: hasta cambiarla no puede usar el sistema (ExigeCambioPassword).
        $admin->forceFill(['debe_cambiar_password' => true])->save();
        $rol = Rol::query()->firstOrCreate(['codigo' => 'ADMIN'], [
            'nombre' => 'Administrador', 'descripcion' => 'Administracion del sistema', 've_datos_sensibles' => true, 'activo' => true,
        ]);
        UsuarioRol::query()->create(['usuario_id' => $admin->id, 'rol_id' => $rol->id, 'ambito_tipo_personal' => 'ambos',
            'vigente_desde' => now()->toDateString(), 'asignado_por' => $admin->id]);

        return [$admin, $clave];
    }

    public static function candidato(string $email): array
    {
        $usuario = Usuario::query()->whereRaw('LOWER(email_institucional) = ?', [mb_strtolower(trim($email))])->firstOrFail();

        return [...$usuario->only(['id', 'nombres', 'apellido_paterno', 'apellido_materno', 'email_institucional']),
            'activo' => $usuario->activo, 'ya_administrador' => self::esAdministrador($usuario)];
    }

    public static function asignarExistente(string $id, string $email): Usuario
    {
        $usuario = Usuario::query()->lockForUpdate()->findOrFail($id);
        abort_unless(mb_strtolower((string) $usuario->email_institucional) === mb_strtolower(trim($email)), 409, 'La identidad cambió. Vuelve a buscar a la persona.');
        $rol = Rol::query()->firstOrCreate(['codigo' => 'ADMIN'], ['nombre' => 'Administrador', 've_datos_sensibles' => true, 'activo' => true]);
        abort_unless($rol->activo, 409, 'El rol de administración no está activo.');
        abort_if(UsuarioRol::query()->where('usuario_id', $usuario->id)->where('rol_id', $rol->id)
            ->where(fn ($q) => $q->whereNull('vigente_hasta')->orWhereDate('vigente_hasta', '>=', today()->toDateString()))->exists(), 409, 'La persona ya tiene una asignación de administración vigente o prevista.');
        UsuarioRol::query()->create(['usuario_id' => $usuario->id, 'rol_id' => $rol->id, 'ambito_tipo_personal' => 'ambos',
            'vigente_desde' => today()->toDateString(), 'asignado_por' => $usuario->id]);

        return $usuario->refresh();
    }

    /** Contraseña temporal nueva para un administrador (olvido o entrega a otra persona). @return array{0: Usuario, 1: string} */
    public static function restablecer(string $usuarioId): array
    {
        $admin = self::administrador($usuarioId);
        $clave = self::claveTemporal();
        $admin->forceFill(['password' => $clave, 'debe_cambiar_password' => true])->save();

        return [$admin, $clave];
    }

    /** Activa o desactiva la cuenta. Nunca deja a la institución sin un administrador activo. */
    public static function cambiarActivo(string $usuarioId, bool $activo): Usuario
    {
        $admin = self::administrador($usuarioId);
        if ($admin->activo === $activo) {
            abort(409, $activo ? 'La cuenta ya está activa.' : 'La cuenta ya está desactivada.');
        }
        if (! $activo) {
            $otrosActivos = collect(self::listar())->where('activo', true)->where('id', '!=', $admin->id)->count();
            abort_if($otrosActivos === 0, 409, 'Es el único administrador activo: la institución quedaría sin administración. Agrega otro antes, o suspende la institución.');
        }
        $admin->forceFill(['activo' => $activo])->save();

        return $admin;
    }

    /** @return array<string, mixed> */
    public static function datos(Usuario $usuario): array
    {
        return [...$usuario->only(['id', 'nombres', 'apellido_paterno', 'email_institucional']),
            'activo' => (bool) $usuario->activo, 'debe_cambiar_password' => (bool) $usuario->debe_cambiar_password,
            'ultimo_acceso' => $usuario->ultimo_acceso?->toIso8601String()];
    }

    /** Solo cuentas de administración: el panel no gestiona otras personas de la institución. */
    private static function administrador(string $usuarioId): Usuario
    {
        $usuario = Usuario::query()->lockForUpdate()->find($usuarioId);
        abort_unless($usuario && self::esAdministrador($usuario), 404, 'No es un administrador de esta institución.');

        return $usuario;
    }

    /**
     * Administrador por sus roles vigentes, esté activa o no la cuenta (una cuenta desactivada no tiene permisos, pero
     * sigue siendo de administración). Se evalúa en una copia limpia: permisos() guarda su resultado en el modelo.
     */
    private static function esAdministrador(Usuario $usuario): bool
    {
        return (new Usuario)->newFromBuilder([...$usuario->getAttributes(), 'activo' => true])->isAdministrator();
    }

    private static function claveTemporal(): string
    {
        return Str::password(16, symbols: false);
    }
}
