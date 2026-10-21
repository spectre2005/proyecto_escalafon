<?php

namespace App\Support\Plataforma;

use App\Models\Plataforma\Institucion;
use FilesystemIterator;
use Illuminate\Support\Facades\DB;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * Uso y estado técnico de una institución para el panel de plataforma. Solo cifras y estados: ningún nombre, DNI ni
 * documento, y ningún nombre interno de base ni ruta en los mensajes.
 *
 * - Personas: todas las filas de usuarios, como el inicio institucional y «Personal»; con acceso = activo.
 * - Legajos: por estado_legajo (activo; pasivo, que incluye el «cerrado» antiguo). No se borran.
 * - Último ingreso: usuarios.ultimo_acceso, que solo escribe el inicio de sesión institucional (AuthController::login):
 *   es el último inicio de sesión, no la última actividad. Aparte, el de quien tiene el rol ADMIN, y cuántas cuentas
 *   iniciaron sesión en los últimos DIAS_ACTIVIDAD días.
 * - Documentos: filas de documentos_usuario (un documento no se borra: se subsana con otro, y ambos cuentan como
 *   cargas). Por mes de created_at en la zona horaria de la plataforma, los últimos MESES meses (el actual incluido).
 * - Espacio: storage/app/private/{almacenamiento}, donde ConexionInstitucion pone todos sus discos privados. No sigue
 *   enlaces simbólicos (apuntan fuera de la institución: se informan y no se cuentan), no cuenta dos veces un archivo
 *   (dispositivo e inodo) y no lee contenidos. Con más de MAX_ARCHIVOS se detiene y la cifra queda como parcial.
 * - Comprobaciones: correcto, aviso, error (confirmado), no_verificado (no se pudo comprobar) o no_disponible.
 *
 * Cada institución usa su propia conexión, que se cierra al terminar: un fallo en una no toca a las demás.
 */
final class UsoInstitucion
{
    public const MAX_ARCHIVOS = 200000;

    public const DIAS_ACTIVIDAD = 30;

    public const MESES = 6;

    private const CONEXION = 'uso_institucion';

    /** @return array<string, mixed> */
    public static function medir(Institucion $institucion): array
    {
        $resultado = ['institucion' => $institucion->only(['id', 'nombre', 'slug', 'estado']), 'medido_en' => now()->toIso8601String(),
            'personas' => null, 'legajos' => null, 'documentos' => null, 'ultimo_ingreso' => null, 'almacenamiento' => null, 'comprobaciones' => []];
        if (! $institucion->aprovisionada()) {
            $resultado['comprobaciones'][] = self::comprobacion('instalacion', 'no_disponible', 'Sin aprovisionar: todavía no tiene base de datos ni carpeta.');

            return [...$resultado, 'atencion' => false];
        }

        $base = self::base($institucion);
        $archivos = self::almacenamiento($institucion);
        $resultado = [...$resultado, ...$base['datos'], ...$archivos['datos'],
            'comprobaciones' => [...$base['comprobaciones'], ...$archivos['comprobaciones'], ...self::respaldos($institucion)]];
        $resultado['atencion'] = collect($resultado['comprobaciones'])->contains(fn (array $c): bool => in_array($c['estado'], ['error', 'aviso'], true));

        return $resultado;
    }

    /** Conteos, último ingreso y esquema, con una conexión propia a la base de la institución. */
    private static function base(Institucion $institucion): array
    {
        $plantilla = (array) config('database.connections.'.config('database.default'));
        config(['database.connections.'.self::CONEXION => [...$plantilla, 'database' => $institucion->base_datos]]);
        try {
            $db = DB::connection(self::CONEXION);
            $db->getPdo();
        } catch (Throwable) {
            DB::purge(self::CONEXION);

            return ['datos' => [], 'comprobaciones' => [
                self::comprobacion('base_datos', 'error', 'No se pudo consultar la base de datos de la institución.'),
                self::comprobacion('esquema', 'no_verificado', 'No se pudo comprobar: la base de datos no respondió.'),
            ]];
        }
        try {
            $datos = [];
            try {
                $personas = $db->table('usuarios')->selectRaw('count(*) as total, sum(case when activo then 1 else 0 end) as con_acceso')->first();
                $estados = $db->table('legajos')->select('estado_legajo', DB::raw('count(*) as total'))->groupBy('estado_legajo')->pluck('total', 'estado_legajo');
                $admin = $db->table('usuarios')->join('usuario_roles', 'usuario_roles.usuario_id', '=', 'usuarios.id')
                    ->join('roles', 'roles.id', '=', 'usuario_roles.rol_id')->where('roles.codigo', 'ADMIN')->max('usuarios.ultimo_acceso');
                $datos = [
                    'personas' => ['total' => (int) $personas->total, 'con_acceso' => (int) $personas->con_acceso],
                    'legajos' => ['total' => (int) $estados->sum(), 'activos' => (int) ($estados['activo'] ?? 0),
                        'pasivos' => (int) (($estados['pasivo'] ?? 0) + ($estados['cerrado'] ?? 0))],
                    'documentos' => self::documentos($db),
                    'ultimo_ingreso' => ['cualquiera' => self::fecha($db->table('usuarios')->max('ultimo_acceso')), 'administrador' => self::fecha($admin),
                        'cuentas_recientes' => $db->table('usuarios')->where('ultimo_acceso', '>=', now()->subDays(self::DIAS_ACTIVIDAD))->count()],
                ];
                $comprobaciones = [self::comprobacion('base_datos', 'correcto', 'Responde.')];
            } catch (Throwable) {
                $comprobaciones = [self::comprobacion('base_datos', 'error', 'La base responde, pero no se pudieron leer sus datos.')];
            }
            $comprobaciones[] = self::esquema($db);

            return ['datos' => $datos, 'comprobaciones' => $comprobaciones];
        } finally {
            DB::purge(self::CONEXION);
        }
    }

    /**
     * Documentos cargados: total y por mes (los últimos MESES, del más antiguo al actual). Un mes es el de la zona horaria
     * de la plataforma; sus límites se pasan a la de la aplicación, que es la de created_at.
     */
    private static function documentos($db): array
    {
        $zona = (string) config('plataforma.zona_horaria');
        $actual = now($zona)->startOfMonth();
        $porMes = [];
        for ($atras = self::MESES - 1; $atras >= 0; $atras--) {
            $inicio = $actual->copy()->subMonthsNoOverflow($atras);
            $porMes[] = ['mes' => $inicio->format('Y-m'), 'total' => $db->table('documentos_usuario')
                ->where('created_at', '>=', $inicio->copy()->setTimezone(config('app.timezone')))
                ->where('created_at', '<', $inicio->copy()->addMonthNoOverflow()->setTimezone(config('app.timezone')))->count()];
        }

        return ['total' => $db->table('documentos_usuario')->count(), 'este_mes' => end($porMes)['total'], 'por_mes' => $porMes];
    }

    /** Migraciones del código que la base aún no tiene (tabla migrations frente a database/migrations). */
    private static function esquema($db): array
    {
        try {
            $aplicadas = $db->table('migrations')->pluck('migration')->all();
        } catch (Throwable) {
            return self::comprobacion('esquema', 'error', 'La base no tiene el registro de migraciones.');
        }
        $codigo = array_map(fn (string $archivo): string => basename($archivo, '.php'), glob(database_path('migrations/*.php')) ?: []);
        $pendientes = count(array_diff($codigo, $aplicadas));

        return $pendientes === 0 ? self::comprobacion('esquema', 'correcto', 'Migraciones al día.')
            : self::comprobacion('esquema', 'aviso', "{$pendientes} migración(es) pendiente(s): ejecutar plataforma:migrar-instituciones.");
    }

    /** Espacio de su carpeta y si se puede usar. */
    private static function almacenamiento(Institucion $institucion): array
    {
        $carpeta = storage_path('app/private/'.$institucion->almacenamiento);
        if (! is_dir($carpeta) || ! is_readable($carpeta)) {
            return ['datos' => [], 'comprobaciones' => [self::comprobacion('almacenamiento', 'error', 'Falta su carpeta de archivos o no se puede leer.')]];
        }
        $bytes = 0;
        $archivos = 0;
        $enlaces = 0;
        $vistos = [];
        $parcial = false;
        try {
            $recorrido = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($carpeta, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
            foreach ($recorrido as $entrada) {
                if ($entrada->isLink()) {
                    $enlaces++;

                    continue;
                }
                if (! $entrada->isFile()) {
                    continue;
                }
                $datos = @lstat($entrada->getPathname());
                if ($datos === false || isset($vistos[$datos['dev'].':'.$datos['ino']])) {
                    continue;
                }
                $vistos[$datos['dev'].':'.$datos['ino']] = true;
                $bytes += $datos['size'];
                if (++$archivos >= self::MAX_ARCHIVOS) {
                    $parcial = true;
                    break;
                }
            }
        } catch (Throwable) {
            return ['datos' => [], 'comprobaciones' => [self::comprobacion('almacenamiento', 'error', 'No se pudo recorrer su carpeta de archivos.')]];
        }
        $comprobaciones = [is_writable($carpeta) ? self::comprobacion('almacenamiento', 'correcto', 'Su carpeta existe y se puede escribir.')
            : self::comprobacion('almacenamiento', 'error', 'Su carpeta existe, pero no se puede escribir: no podrá guardar documentos.')];
        if ($enlaces > 0) {
            $comprobaciones[] = self::comprobacion('enlaces', 'aviso', "Su carpeta tiene {$enlaces} enlace(s) a ubicaciones de fuera: ese espacio no se cuenta y no es exclusivo de la institución.");
        }
        if ($parcial) {
            $comprobaciones[] = self::comprobacion('recorrido', 'aviso', 'Tiene más de '.number_format(self::MAX_ARCHIVOS, 0, ',', '.').' archivos: el espacio mostrado es parcial.');
        }

        return ['datos' => ['almacenamiento' => ['bytes' => $bytes, 'archivos' => $archivos, 'parcial' => $parcial, 'enlaces_externos' => $enlaces]],
            'comprobaciones' => $comprobaciones];
    }

    /** Del registro de respaldos de la plataforma (respaldar.sh). */
    private static function respaldos(Institucion $institucion): array
    {
        $estado = $institucion->estadoRespaldo();
        $ultimo = $estado['ultimo'];
        $respaldo = match (true) {
            $ultimo === null => self::comprobacion('respaldo', 'aviso', 'No hay respaldos registrados.'),
            $estado['atrasado'] => self::comprobacion('respaldo', 'aviso', $estado['ultimo_correcto_en'] ? 'El último respaldo correcto tiene más de 26 horas.' : 'Ningún respaldo correcto registrado.'),
            default => self::comprobacion('respaldo', 'correcto', 'Respaldo correcto reciente.'),
        };
        $externa = match ($ultimo['copia_externa'] ?? null) {
            'correcta' => self::comprobacion('copia_externa', 'correcto', 'La última copia externa cifrada se subió y se comprobó.'),
            'fallida' => self::comprobacion('copia_externa', 'error', 'Falló la última copia externa cifrada.'),
            'no_configurada' => self::comprobacion('copia_externa', 'no_disponible', 'La copia externa cifrada no está configurada.'),
            default => self::comprobacion('copia_externa', 'no_disponible', 'Sin respaldos registrados: no hay copia externa que revisar.'),
        };

        return [$respaldo, $externa];
    }

    private static function fecha(mixed $valor): ?string
    {
        return $valor === null ? null : now()->parse((string) $valor)->toIso8601String();
    }

    /** @return array{clave: string, estado: string, mensaje: string} */
    private static function comprobacion(string $clave, string $estado, string $mensaje): array
    {
        return ['clave' => $clave, 'estado' => $estado, 'mensaje' => $mensaje];
    }
}
