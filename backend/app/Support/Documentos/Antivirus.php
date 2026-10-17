<?php

namespace App\Support\Documentos;

use App\Models\ArchivoDocumento;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class Antivirus
{
    public function archivo(string $disco, string $ruta): string
    {
        if (! config('antivirus.enabled')) {
            return 'pendiente';
        }
        try {
            $stream = Storage::disk($disco)->readStream($ruta);
        } catch (Throwable) {
            return 'error';
        }
        if (! is_resource($stream)) {
            return 'error';
        }
        try {
            return $this->analizar($stream);
        } finally {
            fclose($stream);
        }
    }

    /** @param resource $stream */
    public function analizar(mixed $stream): string
    {
        $socket = null;
        try {
            $socket = @stream_socket_client(config('antivirus.endpoint'), $errno, $error, (float) config('antivirus.timeout_seconds'));
            if (! is_resource($socket)) {
                return 'error';
            }
            stream_set_timeout($socket, (int) config('antivirus.timeout_seconds'));
            $limite = microtime(true) + (int) config('antivirus.timeout_seconds');
            $this->escribir($socket, "zINSTREAM\0", $limite);
            $bytes = 0;
            while (! feof($stream)) {
                $chunk = fread($stream, 8192);
                if ($chunk === false) {
                    throw new RuntimeException('No se pudo leer el archivo.');
                }
                if ($chunk === '') {
                    break;
                }
                $bytes += strlen($chunk);
                if ($bytes > (int) config('antivirus.max_bytes')) {
                    return 'error';
                }
                $this->escribir($socket, pack('N', strlen($chunk)).$chunk, $limite);
            }
            $this->escribir($socket, pack('N', 0), $limite);
            $respuesta = '';
            while (! feof($socket) && ! str_contains($respuesta, "\0") && strlen($respuesta) < 4096) {
                if (microtime(true) >= $limite) {
                    return 'error';
                }
                $parte = fread($socket, 1024);
                if ($parte === false || $parte === '') {
                    return 'error';
                }
                $respuesta .= $parte;
            }
            $respuesta = trim($respuesta, "\0\r\n ");
            if ($respuesta === 'stream: OK') {
                return 'limpio';
            }

            return str_starts_with($respuesta, 'stream: ') && str_ends_with($respuesta, ' FOUND') ? 'infectado' : 'error';
        } catch (Throwable) {
            return 'error';
        } finally {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
    }

    public function exigir(string $disco, string $ruta, ?ArchivoDocumento $archivo = null): void
    {
        abort_if($archivo?->estado_antivirus === 'infectado', 423, 'El archivo está en cuarentena. Registra una subsanación.');
        if (! config('antivirus.enforce')) {
            return;
        }
        if ($archivo?->estado_antivirus === 'limpio') {
            return;
        }
        $estado = $this->archivo($disco, $ruta);
        $archivo?->update(['estado_antivirus' => $estado]);
        abort_unless($estado === 'limpio', 423, 'El archivo está en cuarentena hasta completar un análisis antivirus limpio.');
    }

    /** @param resource $socket */
    private function escribir(mixed $socket, string $bytes, float $limite): void
    {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            if (microtime(true) >= $limite) {
                throw new RuntimeException('Tiempo de análisis agotado.');
            }
            $escritos = fwrite($socket, substr($bytes, $offset));
            if ($escritos === false || $escritos === 0) {
                throw new RuntimeException('El antivirus no está disponible.');
            }
            $offset += $escritos;
        }
    }
}
