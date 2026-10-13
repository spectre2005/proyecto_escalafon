<?php

namespace App\Support\Plataforma;

use App\Models\Plataforma\Institucion;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Atiende una petición (o un trabajo en cola) con los recursos de una institución (una base por cliente): su base de
 * datos (conexión «institucion», copia de la conexión por defecto con otra base), sus archivos, su caché y, con
 * sesiones en archivos, su propia carpeta de sesiones. Al salir, el proceso vuelve a la configuración del .env
 * (pruebas, Octane, el worker de colas).
 *
 * - Archivos: todo disco local dentro de storage/app/private se reubica en storage/app/private/{almacenamiento}, que es
 *   un espejo de esa carpeta: documentos/ (PDF del legajo), fotos-personal/, los PDF anteriores del disco «local», y
 *   los discos que se agreguen después. Ningún archivo de una institución queda en una carpeta común.
 * - Caché: prefijo propio y, en los almacenes en archivos, carpeta propia (storage/framework/cache/instituciones/{slug}).
 *   Incluye el límite de intentos de login: la misma cuenta en dos instituciones no comparte contador.
 */
final class ConexionInstitucion
{
    public const CONEXION = 'institucion';

    public static function usar(Institucion $institucion, Closure $peticion): mixed
    {
        $antes = self::entrar($institucion);
        try {
            return $peticion();
        } finally {
            self::salir($antes);
        }
    }

    /**
     * Cambia el proceso a la institución. Devuelve la configuración anterior, para salir().
     *
     * @return array<string, mixed>
     */
    public static function entrar(Institucion $institucion): array
    {
        $privado = storage_path('app/private');
        $cambios = [];
        foreach ((array) config('filesystems.disks') as $nombre => $disco) {
            $raiz = (string) ($disco['root'] ?? '');
            if (($disco['driver'] ?? null) === 'local' && ($raiz === $privado || str_starts_with($raiz, $privado.'/'))) {
                $cambios["filesystems.disks.{$nombre}.root"] = $privado.'/'.$institucion->almacenamiento.substr($raiz, strlen($privado));
            }
        }
        foreach ((array) config('cache.stores') as $nombre => $almacen) {
            if (($almacen['driver'] ?? null) === 'file') {
                foreach (['path', 'lock_path'] as $clave) {
                    if (! empty($almacen[$clave])) {
                        $cambios["cache.stores.{$nombre}.{$clave}"] = dirname((string) $almacen[$clave]).'/instituciones/'.$institucion->slug;
                    }
                }
            }
        }
        $cambios['cache.prefix'] = config('cache.prefix').$institucion->slug.'-';
        $plantilla = (array) config('database.connections.'.config('database.default'));
        $cambios['database.connections.'.self::CONEXION] = [...$plantilla, 'database' => $institucion->base_datos];
        $cambios['database.default'] = self::CONEXION;
        // Sesiones en archivos: la carpeta es común a todo el servidor; cada institución usa la suya.
        if (config('session.driver') === 'file') {
            $cambios['session.files'] = storage_path('framework/sessions/instituciones/'.$institucion->slug);
            File::ensureDirectoryExists($cambios['session.files']);
        }

        $antes = [];
        foreach (array_keys($cambios) as $clave) {
            $antes[$clave] = config($clave);
        }
        config($cambios);
        self::olvidar();
        app()->instance(Institucion::class, $institucion);

        return $antes;
    }

    /** @param array<string, mixed> $antes lo que devolvió entrar() */
    public static function salir(array $antes): void
    {
        config($antes);
        self::olvidar();
        app()->forgetInstance(Institucion::class);
    }

    /** Descarta lo ya creado con la configuración anterior (conexión, discos, caché, limitador y sesiones). */
    private static function olvidar(): void
    {
        DB::purge(self::CONEXION);
        foreach (array_keys((array) config('filesystems.disks')) as $disco) {
            Storage::forgetDisk($disco);
        }
        app('cache')->forgetDriver(array_keys((array) config('cache.stores')));
        app()->forgetInstance(RateLimiter::class);
        Facade::clearResolvedInstance(RateLimiter::class);
        if (config('session.driver') === 'file') {
            app('session')->forgetDrivers();
        }
    }
}
