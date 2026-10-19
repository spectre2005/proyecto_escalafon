<?php

namespace App\Support\Documentos;

use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Protocolo INSTREAM de clamd: el daemon recibe bytes y no necesita acceso al almacenamiento. */
class AnalizadorAntivirus
{
    public function analizar(string $contenido): void
    {
        $host = (string) config('documentos.antivirus_host');
        $puerto = (int) config('documentos.antivirus_puerto');
        $timeout = max(1, (int) config('documentos.antivirus_timeout'));
        $socket = @stream_socket_client('tcp://'.$host.':'.$puerto, $codigo, $mensaje, $timeout);
        if ($socket === false) {
            throw new HttpException(503, 'El antivirus no está disponible. Reintenta cuando se restablezca el servicio.');
        }
        stream_set_timeout($socket, $timeout);
        try {
            $this->escribir($socket, "zINSTREAM\0");
            for ($inicio = 0, $total = strlen($contenido); $inicio < $total; $inicio += 65536) {
                $trozo = substr($contenido, $inicio, 65536);
                $this->escribir($socket, pack('N', strlen($trozo)).$trozo);
            }
            $this->escribir($socket, pack('N', 0));
            $respuesta = '';
            while (! str_contains($respuesta, "\0") && strlen($respuesta) < 4096) {
                $trozo = @fread($socket, 1024);
                if ($trozo === false || $trozo === '') {
                    break;
                }
                $respuesta .= $trozo;
            }
            if (trim($respuesta, "\0\r\n ") === 'stream: OK') {
                return;
            }
            if (str_ends_with(trim($respuesta, "\0\r\n "), ' FOUND')) {
                throw ValidationException::withMessages(['pdf' => 'El antivirus detectó una amenaza. El archivo no se admite.']);
            }
            throw new HttpException(503, 'El antivirus no pudo completar el análisis. El archivo permanece bloqueado.');
        } finally {
            fclose($socket);
        }
    }

    /** @param resource $socket */
    private function escribir($socket, string $contenido): void
    {
        while ($contenido !== '') {
            $escritos = @fwrite($socket, $contenido);
            if ($escritos === false || $escritos === 0) {
                throw new HttpException(503, 'El antivirus interrumpió el análisis. Intenta nuevamente.');
            }
            $contenido = substr($contenido, $escritos);
        }
    }
}
