<?php

namespace App\Support\Antivirus;

/**
 * Cliente de clamd por TCP con el comando INSTREAM: el archivo viaja en bloques (4 bytes de longitud en orden de red
 * + datos) y termina con un bloque de longitud cero. Respuestas: «stream: OK» (limpio), «stream: <firma> FOUND»
 * (infectado) o «... ERROR» (p. ej. supera StreamMaxLength), que se trata como no disponible.
 */
final class ClamAv implements EscanerAntivirus
{
    private const BLOQUE = 8192;

    public function __construct(
        private readonly string $host,
        private readonly int $puerto,
        private readonly int $tiempoConexion,
        private readonly int $tiempoRespuesta,
    ) {}

    public function activo(): bool
    {
        return true;
    }

    public function escanearArchivo(string $ruta): ?string
    {
        $archivo = @fopen($ruta, 'rb');
        if ($archivo === false) {
            throw new AntivirusNoDisponible('No se pudo leer el archivo a revisar.');
        }
        try {
            return $this->escanear(function ($socket) use ($archivo): void {
                while (! feof($archivo)) {
                    $bloque = fread($archivo, self::BLOQUE);
                    if ($bloque === false) {
                        throw new AntivirusNoDisponible('No se pudo leer el archivo a revisar.');
                    }
                    $this->enviarBloque($socket, $bloque);
                }
            });
        } finally {
            fclose($archivo);
        }
    }

    public function escanearContenido(string $contenido): ?string
    {
        return $this->escanear(function ($socket) use ($contenido): void {
            foreach (str_split($contenido, self::BLOQUE) as $bloque) {
                $this->enviarBloque($socket, $bloque);
            }
        });
    }

    /** Responde si clamd está en línea (PING → PONG). Lo usa produccion:verificar. */
    public function enLinea(): bool
    {
        try {
            $socket = $this->conectar();
            fwrite($socket, "zPING\0");
            $respuesta = stream_get_contents($socket);
            fclose($socket);

            return trim((string) $respuesta, "\0\r\n ") === 'PONG';
        } catch (AntivirusNoDisponible) {
            return false;
        }
    }

    /** @param  callable(resource): void  $enviar */
    private function escanear(callable $enviar): ?string
    {
        $socket = $this->conectar();
        try {
            $this->escribir($socket, "zINSTREAM\0");
            $enviar($socket);
            $this->escribir($socket, pack('N', 0));
            $respuesta = stream_get_contents($socket);
            if ($respuesta === false || stream_get_meta_data($socket)['timed_out']) {
                throw new AntivirusNoDisponible('El antivirus no respondió a tiempo.');
            }
        } finally {
            fclose($socket);
        }

        return self::interpretar(trim($respuesta, "\0\r\n "));
    }

    /** Separado para probar el formato de respuesta sin un clamd real. */
    public static function interpretar(string $respuesta): ?string
    {
        if ($respuesta === 'stream: OK') {
            return null;
        }
        if (preg_match('/^stream: (.+) FOUND$/', $respuesta, $m) === 1) {
            return mb_substr($m[1], 0, 150);
        }

        throw new AntivirusNoDisponible('Respuesta inesperada del antivirus.');
    }

    /** @return resource */
    private function conectar()
    {
        $socket = @stream_socket_client("tcp://{$this->host}:{$this->puerto}", $codigo, $mensaje, $this->tiempoConexion);
        if ($socket === false) {
            throw new AntivirusNoDisponible('No se pudo conectar con el antivirus.');
        }
        stream_set_timeout($socket, $this->tiempoRespuesta);

        return $socket;
    }

    /** @param  resource  $socket */
    private function enviarBloque($socket, string $bloque): void
    {
        if ($bloque !== '') {
            $this->escribir($socket, pack('N', strlen($bloque)).$bloque);
        }
    }

    /** @param  resource  $socket */
    private function escribir($socket, string $datos): void
    {
        $pendiente = $datos;
        while ($pendiente !== '') {
            $escritos = @fwrite($socket, $pendiente);
            if ($escritos === false || $escritos === 0) {
                // clamd corta la conexión cuando el archivo supera su StreamMaxLength: lo que haya respondido se lee después.
                return;
            }
            $pendiente = substr($pendiente, $escritos);
        }
    }
}
