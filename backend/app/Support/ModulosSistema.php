<?php

namespace App\Support;

use App\Models\AuditoriaEvento;
use App\Models\ConfiguracionFuncionalidad;
use App\Models\Plataforma\Institucion;
use App\Models\Usuario;
use App\Support\Plataforma\AuditoriaOperaciones;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Módulos independientes de las secciones: una sola configuración compartida por los dos administradores. */
final class ModulosSistema
{
    public const CATALOGO = ['evaluaciones' => [
        'nombre' => 'Evaluaciones del menú principal',
        'descripcion' => 'Consulta general de evaluaciones, progresiones, ratificaciones y desplazamientos. La sección 07 del legajo y los reportes conservan su configuración independiente.',
    ]];

    public static function institucionActual(): ?Institucion
    {
        return app()->bound(Institucion::class) ? app(Institucion::class) : null;
    }

    public static function estados(?Institucion $institucion = null): array
    {
        $institucion ??= self::institucionActual();
        if ($institucion) {
            $guardados = $institucion->modulos_sistema ?? [];
        } else {
            $guardados = ConfiguracionFuncionalidad::query()->whereIn('codigo', array_map(fn (string $codigo): string => 'menu.'.$codigo, array_keys(self::CATALOGO)))
                ->with('updatedBy')->get()->mapWithKeys(fn (ConfiguracionFuncionalidad $r): array => [substr($r->codigo, 5) => [
                    'activa' => $r->activa, 'motivo' => $r->motivo, 'fecha' => $r->updated_at?->toIso8601String(),
                    'por' => trim($r->updatedBy?->nombres.' '.$r->updatedBy?->apellido_paterno), 'origen' => 'institucion',
                ]])->all();
        }
        $resultado = [];
        foreach (self::CATALOGO as $codigo => $definicion) {
            $guardado = $guardados[$codigo] ?? null;
            $resultado[] = ['codigo' => $codigo, ...$definicion, 'activa' => $guardado['activa'] ?? true,
                'ultimo_cambio' => $guardado ? array_intersect_key($guardado, array_flip(['motivo', 'fecha', 'por', 'origen'])) : null];
        }

        return $resultado;
    }

    public static function activa(string $codigo): bool
    {
        abort_unless(isset(self::CATALOGO[$codigo]), 404);

        return collect(self::estados())->firstWhere('codigo', $codigo)['activa'];
    }

    public static function cambiar(string $codigo, bool $activa, bool $esperada, string $motivo, Request $request, ?Institucion $institucion = null): array
    {
        abort_unless(isset(self::CATALOGO[$codigo]), 404);
        $institucion ??= self::institucionActual();
        if (! $institucion) {
            return self::cambiarLocal($codigo, $activa, $esperada, $motivo, $request);
        }

        $resultado = DB::connection('plataforma')->transaction(function () use ($institucion, $codigo, $activa, $esperada, $motivo, $request): Institucion {
            $registro = Institucion::query()->lockForUpdate()->findOrFail($institucion->id);
            $modulos = $registro->modulos_sistema ?? [];
            $antes = $modulos[$codigo]['activa'] ?? true;
            self::comprobarEstado($antes, $esperada, $activa);
            $plataforma = $request->is('api/plataforma/*');
            $autor = $plataforma ? Auth::guard('plataforma')->user() : $request->user();
            $nombre = $plataforma ? $autor->nombre : trim($autor->nombres.' '.$autor->apellido_paterno.' '.$autor->apellido_materno);
            $modulos[$codigo] = ['activa' => $activa, 'motivo' => $motivo, 'fecha' => now()->toIso8601String(), 'por' => $nombre,
                'origen' => $plataforma ? 'plataforma' : 'institucion'];
            $registro->forceFill(['modulos_sistema' => $modulos])->save();
            AuditoriaOperaciones::registrar($activa ? 'modulo.activado' : 'modulo.desactivado', $request, $plataforma ? $autor->id : null, $registro->id,
                ['codigo' => $codigo, 'activa' => $antes], ['codigo' => $codigo, ...$modulos[$codigo], 'actor_id' => $autor->id], $motivo);

            return $registro;
        });
        $institucion->setAttribute('modulos_sistema', $resultado->modulos_sistema);

        return collect(self::estados($resultado))->firstWhere('codigo', $codigo);
    }

    private static function comprobarEstado(bool $actual, bool $esperada, bool $nueva): void
    {
        abort_unless($actual === $esperada && $actual !== $nueva, 409, 'La configuración cambió. Actualiza los módulos antes de volver a guardar.');
    }

    /** Instalación de una sola institución: mantiene el ajuste en su propia base y su auditoría. */
    private static function cambiarLocal(string $codigo, bool $activa, bool $esperada, string $motivo, Request $request): array
    {
        /** @var Usuario $actor */
        $actor = $request->user();
        DB::transaction(function () use ($codigo, $activa, $esperada, $motivo, $request, $actor): void {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('select pg_advisory_xact_lock(?)', [crc32('modulos_sistema')]);
            }
            $registro = ConfiguracionFuncionalidad::query()->lockForUpdate()->firstOrNew(['codigo' => 'menu.'.$codigo]);
            self::comprobarEstado($registro->exists ? $registro->activa : true, $esperada, $activa);
            $registro->fill(['activa' => $activa, 'motivo' => $motivo, 'updated_by' => $actor->id])->save();
            $datos = ['fecha_evento' => now()->format('Y-m-d H:i:s'), 'actor_usuario_id' => $actor->id, 'usuario_titular_id' => null,
                'accion' => $activa ? 'modulo.activar' : 'modulo.desactivar', 'entidad' => 'modulos_sistema', 'entidad_id' => $registro->id,
                'datos_antes' => json_encode(['codigo' => $codigo, 'activa' => $esperada], JSON_THROW_ON_ERROR),
                'datos_despues' => json_encode(['codigo' => $codigo, 'activa' => $activa, 'motivo' => $motivo], JSON_THROW_ON_ERROR),
                'ip_origen' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 300), 'request_id' => (string) Str::uuid(),
                'hash_previo' => AuditoriaEvento::query()->where('entidad', 'modulos_sistema')->where('entidad_id', $registro->id)->orderByDesc('id')->value('hash_registro')];
            AuditoriaEvento::query()->create([...$datos, 'hash_registro' => hash('sha256', json_encode($datos, JSON_THROW_ON_ERROR))]);
        });

        return collect(self::estados())->firstWhere('codigo', $codigo);
    }
}
