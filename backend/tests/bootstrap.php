<?php

use Illuminate\Filesystem\Filesystem;

/*
 * Arranque de PHPUnit: storage propio y temporal en cada ejecución (LARAVEL_STORAGE_PATH). Así, las pruebas no
 * escriben ni borran sesiones, caché, logs, documentos ni discos falsos en backend/storage, aunque una prueba elija
 * por su cuenta el driver de archivo (p. ej. ColasCacheInstitucionTest) o el modo subdominio
 * (storage/framework/sessions/instituciones/{slug}). Al terminar se borra solo esa carpeta.
 */

require __DIR__.'/../vendor/autoload.php';

// PHPUnit aplica <env force="true"> en $_ENV y putenv, pero no en $_SERVER, y env() de Laravel lee primero $_SERVER:
// ahí seguiría el valor del contenedor (docker-compose.yml: SESSION_DRIVER=file, CACHE_STORE=file). En este punto
// $_ENV ya tiene las variables del proceso con las de phpunit.xml aplicadas; se copian a $_SERVER.
foreach ($_ENV as $nombre => $valor) {
    if (is_string($valor)) {
        $_SERVER[$nombre] = $valor;
    }
}

$storage = sys_get_temp_dir().'/escalafon-pruebas-'.getmypid();
foreach (['app/private', 'app/public', 'framework/cache/data', 'framework/sessions', 'framework/testing', 'framework/views', 'logs'] as $carpeta) {
    if (! is_dir($storage.'/'.$carpeta)) {
        mkdir($storage.'/'.$carpeta, 0775, true);
    }
}
$_ENV['LARAVEL_STORAGE_PATH'] = $_SERVER['LARAVEL_STORAGE_PATH'] = $storage;
putenv('LARAVEL_STORAGE_PATH='.$storage);

register_shutdown_function(static function () use ($storage): void {
    // Solo la carpeta temporal de esta ejecución.
    if (str_starts_with($storage, sys_get_temp_dir().'/escalafon-pruebas-') && is_dir($storage)) {
        (new Filesystem)->deleteDirectory($storage);
    }
});
