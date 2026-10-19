<?php

namespace App\Support\Plataforma;

use App\Models\Plataforma\Institucion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/** Exportación portable de datos y archivos; las claves externas se custodian por separado. */
final class ExportacionInstitucion
{
    public static function generar(Institucion $institucion): array
    {
        abort_unless(in_array($institucion->estado, [Institucion::SUSPENDIDA, Institucion::ARCHIVADA], true) && $institucion->aprovisionada(), 409, 'Suspende primero una institución aprovisionada antes de exportarla.');
        abort_unless(preg_match(Institucion::PATRON_ALMACENAMIENTO, $institucion->almacenamiento) === 1, 409, 'El almacenamiento no es válido.');
        $carpeta = storage_path('framework/exportaciones/'.Str::uuid());
        $directorio = $carpeta.'/datos';
        File::ensureDirectoryExists($directorio, 0700);
        $ruta = $carpeta.'/exportacion.zip';
        $zip = new ZipArchive;
        if ($zip->open($ruta, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            File::deleteDirectory($directorio);
            throw new RuntimeException('No se pudo crear la exportación privada.');
        }
        try {
            $manifest = ConexionInstitucion::usar($institucion, function () use ($institucion, $directorio, $zip): array {
                $manifest = ['formato' => 'escalafon-json-zip-v1', 'generado_en' => now()->toIso8601String(),
                    'institucion' => $institucion->only(['id', 'nombre', 'slug']), 'tablas' => [], 'archivos' => [],
                    'cifrado' => 'Los archivos conservan su cifrado original. Las claves documentales no se incluyen; se requieren para restaurar o descifrar los PDF.',
                    'restauracion' => 'Datos JSON y esquema descriptivo; no es un volcado SQL ni una restauración automática.'];
                DB::transaction(function () use ($directorio, $zip, &$manifest): void {
                    if (DB::getDriverName() === 'pgsql') {
                        DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
                    }
                    $esquema = Schema::connection(DB::getDefaultConnection());
                    foreach ($esquema->getTables() as $tabla) {
                        $nombre = $tabla['name'];
                        if (isset($tabla['schema']) && ! in_array($tabla['schema'], ['public', 'main'], true)) {
                            continue;
                        }
                        if (preg_match('/^[a-z][a-z0-9_]*$/', $nombre) !== 1) {
                            throw new RuntimeException('El nombre de una tabla no es exportable.');
                        }
                        $archivo = $directorio.'/'.$nombre.'.json';
                        $salida = fopen($archivo, 'xb');
                        if ($salida === false) {
                            throw new RuntimeException('No se pudo preparar un archivo de datos.');
                        }
                        chmod($archivo, 0600);
                        $filas = 0;
                        try {
                            self::escribir($salida, '[');
                            foreach (DB::table($nombre)->cursor() as $fila) {
                                self::escribir($salida, ($filas ? ',' : '').json_encode($fila, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                                $filas++;
                            }
                            self::escribir($salida, ']');
                        } finally {
                            fclose($salida);
                        }
                        if (! $zip->addFile($archivo, 'datos/'.$nombre.'.json')) {
                            throw new RuntimeException('No se pudo añadir una tabla al ZIP.');
                        }
                        $manifest['tablas'][$nombre] = ['filas' => $filas, 'sha256' => hash_file('sha256', $archivo),
                            'columnas' => $esquema->getColumns($nombre), 'indices' => $esquema->getIndexes($nombre), 'claves_foraneas' => $esquema->getForeignKeys($nombre)];
                    }
                });
                $raiz = storage_path('app/private/'.$institucion->almacenamiento);
                abort_unless(is_dir($raiz), 409, 'No se encontró la carpeta privada de la institución.');
                $real = realpath($raiz);
                $privado = realpath(storage_path('app/private'));
                abort_unless($real && $privado && str_starts_with($real, $privado.DIRECTORY_SEPARATOR), 409, 'La carpeta privada no está dentro del almacenamiento institucional.');
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS)) as $archivo) {
                    if ($archivo->isLink()) {
                        throw new RuntimeException('La carpeta contiene un enlace simbólico que requiere revisión.');
                    }
                    if (! $archivo->isFile()) {
                        continue;
                    }
                    $relativa = str_replace(DIRECTORY_SEPARATOR, '/', substr($archivo->getPathname(), strlen($real) + 1));
                    if (! $zip->addFile($archivo->getPathname(), 'archivos/'.$relativa)) {
                        throw new RuntimeException('No se pudo añadir un archivo institucional al ZIP.');
                    }
                    $manifest['archivos'][$relativa] = ['bytes' => $archivo->getSize(), 'sha256' => hash_file('sha256', $archivo->getPathname())];
                }

                return $manifest;
            });
            if (! $zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) || ! $zip->close()) {
                throw new RuntimeException('No se pudo finalizar la exportación.');
            }
            chmod($ruta, 0600);
            clearstatcache(true, $ruta);
            app()->terminating(static fn () => File::deleteDirectory($carpeta));

            return ['ruta' => $ruta, 'tablas' => count($manifest['tablas']), 'archivos' => count($manifest['archivos']), 'sha256' => hash_file('sha256', $ruta)];
        } catch (Throwable $error) {
            try {
                $zip->close();
            } catch (Throwable) {
            }
            File::delete($ruta);
            throw $error;
        } finally {
            File::deleteDirectory($directorio);
            if (! is_file($ruta)) {
                File::deleteDirectory($carpeta);
            }
        }
    }

    /** @param resource $salida */
    private static function escribir($salida, string $contenido): void
    {
        if (fwrite($salida, $contenido) !== strlen($contenido)) {
            throw new RuntimeException('No se pudo completar el archivo de datos.');
        }
    }
}
