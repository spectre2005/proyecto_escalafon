<?php

namespace App\Console\Commands;

use App\Support\Documentos\AlmacenDocumento;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

class GenerarClaveDocumentos extends Command
{
    protected $signature = 'documentos:clave';

    protected $description = 'Genera una clave privada de documentos una sola vez, sin mostrarla ni sobrescribir claves existentes';

    public function handle(): int
    {
        if (config('documentos.clave') || is_file((string) config('documentos.archivo_clave'))) {
            try {
                AlmacenDocumento::cifrar('verificacion');
            } catch (Throwable) {
                $this->error('La clave existente no es válida. Se conserva sin cambios; restaura su copia segura antes de continuar.');

                return self::FAILURE;
            }
        }
        if (config('documentos.clave')) {
            $this->info('Se usa la clave proporcionada por configuración.');

            return self::SUCCESS;
        }
        $ruta = (string) config('documentos.archivo_clave');
        File::ensureDirectoryExists(dirname($ruta), 0700);
        if (is_file($ruta)) {
            $this->info('La clave ya existe; se conserva sin cambios.');

            return self::SUCCESS;
        }
        $archivo = @fopen($ruta, 'x');
        if ($archivo === false) {
            $this->error('No se pudo crear la clave. Comprueba los permisos de la carpeta privada.');

            return self::FAILURE;
        }
        chmod($ruta, 0600);
        $clave = 'base64:'.base64_encode(random_bytes(32));
        $completo = fwrite($archivo, $clave) === strlen($clave);
        fclose($archivo);
        if (! $completo) {
            $this->error('No se pudo completar la clave. Revisa el almacenamiento antes de cargar documentos.');

            return self::FAILURE;
        }
        $this->info('Clave privada creada. Inclúyela en el respaldo seguro; no la subas al repositorio.');

        return self::SUCCESS;
    }
}
