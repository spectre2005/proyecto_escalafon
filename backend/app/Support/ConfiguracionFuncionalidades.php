<?php

namespace App\Support;

use App\Models\AuditoriaEvento;
use App\Models\ConfiguracionFuncionalidad;
use App\Models\DocumentoUsuario;
use App\Models\TipoDocumento;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
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
 * - Una funcionalidad con bloqueo, o esencial con una dependencia permanente (núcleo), no se puede desactivar y
 *   siempre está activa. Ninguna se desactiva mientras otra activa dependa de ella, ni se activa si le falta una
 *   dependencia.
 * - Con una funcionalidad inactiva: sus rutas de creación y modificación y la subida de sus tipos de documento
 *   responden 409 (App\Http\Middleware\FuncionalidadActiva), sus tipos no se exigen (PerfilDocumental) y sus
 *   entradas de menú se ocultan (estadoPublico). Una sección sin funcionalidades activas se oculta.
 * - No consulta permisos: quién cambia la configuración lo decide la ruta (configuracion.modulos).
 */
final class ConfiguracionFuncionalidades
{
    private const MEMORIA = 'configuracion_funcionalidades.guardadas';

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

        return self::guardadas()[$codigo] ?? null;
    }

    /**
     * Estados guardados, leídos una vez por petición (las exigencias consultan cada tipo de documento).
     *
     * @return array<string, bool>
     */
    private static function guardadas(): array
    {
        if (! app()->bound(self::MEMORIA)) {
            app()->instance(self::MEMORIA, ConfiguracionFuncionalidad::query()->pluck('activa', 'codigo')
                ->map(fn (mixed $activa): bool => (bool) $activa)->all());
        }

        return app(self::MEMORIA);
    }

    /** Descarta los estados leídos: se llama al guardar o borrar una fila de configuración. */
    public static function olvidar(): void
    {
        app()->forgetInstance(self::MEMORIA);
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

    /** Núcleo: esencial con una dependencia permanente; nunca se desactiva. */
    public static function nucleo(string $codigo): bool
    {
        return (self::definicion($codigo)['esencial']['permanente'] ?? []) !== [];
    }

    /** Advertencia normativa al desactivar algo de una de las 12 secciones (art. 3.1), o null. */
    public static function advertencia(string $codigo): ?string
    {
        $seccion = self::definicion($codigo)['seccion'];

        return (self::secciones()[$seccion]['caracter'] ?? null) === 'normativa' ? config('funcionalidades.advertencia_normativa') : null;
    }

    /** @return list<string> Funcionalidades a las que pertenece un tipo de documento (por código o por ['*'] de su sección). */
    public static function deTipo(string $codigoTipo, int $seccion): array
    {
        return array_keys(array_filter(self::catalogo(), fn (array $definicion): bool => $definicion['seccion'] === $seccion
            && (in_array('*', $definicion['documentos'], true) || in_array($codigoTipo, $definicion['documentos'], true))));
    }

    /** Un tipo sin funcionalidad («Otros documentos») sigue activo; si tiene, basta con que una esté activa. */
    public static function tipoActivo(string $codigoTipo, int $seccion): bool
    {
        $funcionalidades = self::deTipo($codigoTipo, $seccion);

        return $funcionalidades === [] || collect($funcionalidades)->contains(fn (string $codigo): bool => self::activa($codigo));
    }

    /** @return list<string> Funcionalidades que declaran esta ruta de escritura (con sus restricciones de parámetros). */
    public static function deRuta(Route $ruta, string $metodo): array
    {
        $firma = $metodo.' '.$ruta->uri();
        $codigos = [];
        foreach (self::catalogo() as $codigo => $definicion) {
            foreach ($definicion['rutas_escritura'] as $entrada) {
                [$patron, $restricciones] = is_array($entrada) ? $entrada : [$entrada, []];
                if ($patron === $firma && collect($restricciones)->every(fn (array $valores, string $parametro): bool => in_array($ruta->parameter($parametro), $valores, true))) {
                    $codigos[] = $codigo;
                }
            }
        }

        return array_values(array_unique($codigos));
    }

    /**
     * Funcionalidad inactiva que declara esta ruta de escritura (rutas_escritura, con sus restricciones).
     *
     * @return ?string nombre de la funcionalidad, o null si la ruta puede seguir
     */
    public static function inactivaPorRuta(Route $ruta, string $metodo): ?string
    {
        foreach (self::deRuta($ruta, $metodo) as $codigo) {
            if (! self::activa($codigo)) {
                return self::definicion($codigo)['nombre'];
            }
        }

        return null;
    }

    /**
     * Funcionalidad inactiva a la que pertenece un tipo de documento (por su id), si todas las suyas están inactivas.
     *
     * @return ?string nombre de la funcionalidad, o null si el tipo se puede subir
     */
    public static function inactivaPorTipo(mixed $tipoId): ?string
    {
        $tipo = is_string($tipoId) && Str::isUuid($tipoId) ? TipoDocumento::query()->find($tipoId, ['codigo', 'seccion_legajo_id']) : null;
        if (! $tipo || self::tipoActivo((string) $tipo->codigo, (int) $tipo->seccion_legajo_id)) {
            return null;
        }

        return self::definicion(self::deTipo((string) $tipo->codigo, (int) $tipo->seccion_legajo_id)[0])['nombre'];
    }

    /** Una sección se oculta cuando tiene funcionalidades con menú y ninguna está activa. */
    public static function seccionOculta(int $seccion): bool
    {
        $conMenu = array_keys(array_filter(self::catalogo(), fn (array $definicion): bool => $definicion['seccion'] === $seccion && $definicion['menu'] !== []));

        return $conMenu !== [] && ! collect($conMenu)->contains(fn (string $codigo): bool => self::activa($codigo));
    }

    /** Si desactivarla dejaría su sección oculta (era la última funcionalidad activa con menú). */
    public static function ocultaSeccionAlDesactivar(string $codigo): bool
    {
        $definicion = self::definicion($codigo);
        if ($definicion['menu'] === [] || ! self::activa($codigo)) {
            return false;
        }

        return ! collect(self::catalogo())->contains(fn (array $otra, string $otro): bool => $otro !== $codigo
            && $otra['seccion'] === $definicion['seccion'] && $otra['menu'] !== [] && self::activa($otro));
    }

    /** Registros guardados de una funcionalidad (documentos de sus tipos, filas de sus tablas o valores de sus campos). */
    public static function registros(string $codigo): int
    {
        $definicion = self::definicion($codigo);
        $total = 0;
        if ($definicion['documentos'] !== []) {
            $tipos = TipoDocumento::query()->where('seccion_legajo_id', $definicion['seccion'])
                ->when(! in_array('*', $definicion['documentos'], true), fn ($consulta) => $consulta->whereIn('codigo', $definicion['documentos']))
                ->pluck('id');
            $total += DocumentoUsuario::query()->whereIn('tipo_documento_id', $tipos)->count();
        }
        foreach ($definicion['tablas'] as $tabla) {
            $total += DB::table($tabla)->count();
        }
        foreach ($definicion['campos'] ?? [] as $campo) {
            [$tabla, $columna] = explode('.', $campo);
            $total += DB::table($tabla)->whereNotNull($columna)->count();
        }

        return $total;
    }

    /**
     * Estado para cualquier sesión: qué ocultar en la navegación y qué tipos de documento no se ofrecen.
     *
     * @return array{activas: array<string, bool>, menu_oculto: list<string>, secciones_ocultas: list<int>, tipos_inactivos: list<string>}
     */
    public static function estadoPublico(): array
    {
        $activas = collect(self::catalogo())->map(fn (array $definicion, string $codigo): bool => self::activa($codigo))->all();
        $menuOculto = collect(self::catalogo())->filter(fn (array $definicion, string $codigo): bool => ! $activas[$codigo])
            ->flatMap(fn (array $definicion): array => $definicion['menu'])->unique()->values()->all();
        $tiposInactivos = TipoDocumento::query()->where('activo', true)->get(['codigo', 'seccion_legajo_id'])
            ->reject(fn (TipoDocumento $tipo): bool => self::tipoActivo((string) $tipo->codigo, (int) $tipo->seccion_legajo_id))
            ->pluck('codigo')->unique()->values()->all();

        return ['activas' => $activas, 'menu_oculto' => $menuOculto,
            'secciones_ocultas' => array_values(array_filter(array_keys(self::secciones()), fn (int $seccion): bool => self::seccionOculta($seccion))),
            'tipos_inactivos' => $tiposInactivos];
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
                'esencial' => $definicion['esencial'] !== null, 'nucleo' => self::nucleo($codigo), 'configurable' => self::configurable($codigo),
                'depende_de' => $definicion['depende_de'], 'campos' => $definicion['campos'] ?? [], 'menu' => $definicion['menu'],
                'activa' => $activa, 'guardada' => self::guardada($codigo),
                'bloqueos' => $activa ? self::bloqueosParaDesactivar($codigo) : self::bloqueosParaActivar($codigo),
                'advertencia' => self::advertencia($codigo), 'oculta_seccion' => self::ocultaSeccionAlDesactivar($codigo),
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
            self::olvidar();
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
