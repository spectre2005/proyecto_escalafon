<?php

namespace App\Support;

use App\Models\AuditoriaEvento;
use App\Models\ConfiguracionFuncionalidad;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Estado efectivo de las funcionalidades configurables (docs/decisiones-configuracion-secciones.md).
 *
 * - El catálogo está en config/funcionalidades.php; la base solo guarda los cambios explícitos. Sin fila, una
 *   funcionalidad implementada está activa: el estado inicial conserva el comportamiento actual.
 * - Lo implementado se considera disponible (primera versión, sin planes comerciales).
 * - Una funcionalidad con bloqueo, o esencial con una dependencia permanente, no se puede desactivar y siempre
 *   está activa. Ninguna se desactiva mientras otra activa dependa de ella, ni se activa si le falta una dependencia.
 * - No consulta permisos ni bloquea rutas: eso corresponde a etapas posteriores.
 */
final class ConfiguracionFuncionalidades
{
    /** @return array<string, array<string, mixed>> */
    public static function catalogo(): array
    {
        return config('funcionalidades.funcionalidades');
    }

    /** @return array<int, array{caracter: string, implementada: bool}> */
    public static function secciones(): array
    {
        return config('funcionalidades.secciones');
    }

    /** @return array<string, mixed> */
    public static function definicion(string $codigo): array
    {
        return self::catalogo()[$codigo] ?? throw new InvalidArgumentException("La funcionalidad {$codigo} no existe en el catálogo.");
    }

    public static function disponible(string $codigo): bool
    {
        $definicion = self::definicion($codigo);

        return $definicion['implementada'] && (self::secciones()[$definicion['seccion']]['implementada'] ?? false);
    }

    /** Si el catálogo permite, en principio, desactivarla. Las dependencias de otras funcionalidades se comprueban al cambiar. */
    public static function configurable(string $codigo): bool
    {
        $definicion = self::definicion($codigo);

        return self::disponible($codigo) && $definicion['bloqueo'] === null
            && ($definicion['esencial'] === null || $definicion['esencial']['permanente'] === []);
    }

    /** Estado guardado, o null si nadie lo ha cambiado. */
    public static function guardada(string $codigo): ?bool
    {
        self::definicion($codigo);

        return ConfiguracionFuncionalidad::query()->where('codigo', $codigo)->value('activa');
    }

    public static function activa(string $codigo): bool
    {
        if (! self::disponible($codigo)) {
            return false;
        }

        return ! self::configurable($codigo) || (self::guardada($codigo) ?? true);
    }

    /** @return list<string> Funcionalidades que dependen de la indicada. */
    public static function dependientes(string $codigo): array
    {
        self::definicion($codigo);

        return array_keys(array_filter(self::catalogo(), fn (array $definicion): bool => in_array($codigo, $definicion['depende_de'], true)));
    }

    /** @return list<string> Motivos por los que hoy no se puede desactivar (vacío si se puede). */
    public static function bloqueosParaDesactivar(string $codigo): array
    {
        $definicion = self::definicion($codigo);
        if (! self::disponible($codigo)) {
            return ['No está implementada.'];
        }
        $motivos = $definicion['bloqueo'] ? [$definicion['bloqueo']['motivo']] : [];
        $esencial = $definicion['esencial'];
        foreach ($esencial['permanente'] ?? [] as $dependencia) {
            $motivos[] = "Es esencial ({$esencial['motivo']}) y sigue vigente: {$dependencia}";
        }
        foreach (self::dependientes($codigo) as $dependiente) {
            if (self::activa($dependiente)) {
                $nombre = self::definicion($dependiente)['nombre'];
                $motivos[] = $esencial ? "Es esencial ({$esencial['motivo']}) y la usa «{$nombre}», que está activa."
                    : "La usa «{$nombre}», que está activa.";
            }
        }

        return $motivos;
    }

    /** @return list<string> Motivos por los que hoy no se puede activar (vacío si se puede). */
    public static function bloqueosParaActivar(string $codigo): array
    {
        $definicion = self::definicion($codigo);
        if (! self::disponible($codigo)) {
            return ['No está implementada.'];
        }

        return array_values(array_map(fn (string $dependencia): string => 'Necesita «'.self::definicion($dependencia)['nombre'].'», que está inactiva.',
            array_filter($definicion['depende_de'], fn (string $dependencia): bool => ! self::activa($dependencia))));
    }

    /** @return array<string, array<string, mixed>> Estado de todo el catálogo. */
    public static function estados(): array
    {
        $estados = [];
        foreach (self::catalogo() as $codigo => $definicion) {
            $activa = self::activa($codigo);
            $estados[$codigo] = [
                'codigo' => $codigo, 'nombre' => $definicion['nombre'], 'seccion' => $definicion['seccion'],
                'implementada' => $definicion['implementada'], 'disponible' => self::disponible($codigo),
                'esencial' => $definicion['esencial'] !== null, 'activa' => $activa, 'guardada' => self::guardada($codigo),
                'bloqueos' => $activa ? self::bloqueosParaDesactivar($codigo) : self::bloqueosParaActivar($codigo),
            ];
        }

        return $estados;
    }

    /**
     * Cambia el estado y deja un evento en auditoria_eventos con el estado anterior, el nuevo y el motivo.
     * No toca registros, documentos, folios ni permisos.
     *
     * @throws ValidationException si falta el motivo, el estado no cambia o una restricción lo impide.
     */
    public static function cambiar(string $codigo, bool $activa, string $motivo, Usuario $actor, ?Request $request = null): ConfiguracionFuncionalidad
    {
        self::definicion($codigo);
        $motivo = trim($motivo);
        if ($motivo === '' || mb_strlen($motivo) > 500) {
            throw ValidationException::withMessages(['motivo' => 'Indica el motivo del cambio (hasta 500 caracteres).']);
        }

        return DB::transaction(function () use ($codigo, $activa, $motivo, $actor, $request): ConfiguracionFuncionalidad {
            // Serializa los cambios: las dependencias se comprueban sobre un estado que nadie más está cambiando.
            if (DB::getDriverName() === 'pgsql') {
                DB::select('select pg_advisory_xact_lock(?)', [crc32('configuracion_funcionalidades')]);
            }
            $antes = self::activa($codigo);
            if ($antes === $activa) {
                throw ValidationException::withMessages(['activa' => $activa ? 'La funcionalidad ya está activa.' : 'La funcionalidad ya está inactiva.']);
            }
            $bloqueos = $activa ? self::bloqueosParaActivar($codigo) : self::bloqueosParaDesactivar($codigo);
            if ($bloqueos !== []) {
                throw ValidationException::withMessages(['activa' => $bloqueos]);
            }
            $registro = ConfiguracionFuncionalidad::query()->lockForUpdate()->firstOrNew(['codigo' => $codigo]);
            $registro->fill(['activa' => $activa, 'motivo' => $motivo, 'updated_by' => $actor->getKey()])->save();
            self::auditar($registro, $antes, $actor, $request);

            return $registro;
        });
    }

    private static function auditar(ConfiguracionFuncionalidad $registro, bool $antes, Usuario $actor, ?Request $request): void
    {
        $datos = [
            'fecha_evento' => now()->format('Y-m-d H:i:s'), 'actor_usuario_id' => $actor->getKey(), 'usuario_titular_id' => null,
            'accion' => $registro->activa ? 'funcionalidad.activar' : 'funcionalidad.desactivar',
            'entidad' => 'configuracion_funcionalidades', 'entidad_id' => $registro->id,
            'datos_antes' => json_encode(['codigo' => $registro->codigo, 'activa' => $antes], JSON_THROW_ON_ERROR),
            'datos_despues' => json_encode(['codigo' => $registro->codigo, 'activa' => $registro->activa, 'motivo' => $registro->motivo], JSON_THROW_ON_ERROR),
            'ip_origen' => $request?->ip(), 'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 300) : null,
            'request_id' => (string) Str::uuid(),
            'hash_previo' => AuditoriaEvento::query()->where('entidad', 'configuracion_funcionalidades')->where('entidad_id', $registro->id)
                ->orderByDesc('id')->value('hash_registro'),
        ];
        AuditoriaEvento::query()->create([...$datos, 'hash_registro' => hash('sha256', json_encode($datos, JSON_THROW_ON_ERROR))]);
    }
}
