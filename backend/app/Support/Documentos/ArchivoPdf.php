<?php

namespace App\Support\Documentos;

use Illuminate\Http\UploadedFile;

/**
 * Comprobaciones del PDF en el servidor: tipo real por contenido (no por extensión ni por el
 * Content-Type que manda el navegador), hash SHA-256 y número de páginas.
 */
final class ArchivoPdf
{
    private const MAX_BYTES = 8 * 1024 * 1024;

    private const MAX_BYTES_EXPANDIDOS = 8 * 1024 * 1024;

    private const MAX_STREAMS = 64;

    /** El contenido empieza por la firma %PDF- (se toleran bytes previos, como permite la norma) y finfo lo reconoce como PDF. */
    public static function esPdf(UploadedFile $archivo): bool
    {
        $ruta = $archivo->getRealPath();
        if ($ruta === false || ! is_readable($ruta)) {
            return false;
        }
        $inicio = (string) file_get_contents($ruta, false, null, 0, 1024);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($ruta);

        return str_contains($inicio, '%PDF-') && $mime === 'application/pdf';
    }

    public static function hash(UploadedFile $archivo): string
    {
        return hash_file('sha256', $archivo->getRealPath());
    }

    /**
     * Número de páginas, lo mejor posible sin librería externa: /Count del árbol de páginas (el mayor es la
     * raíz), buscando también en los object streams comprimidos. Si no se puede leer, null (columna opcional).
     */
    public static function paginas(UploadedFile $archivo): ?int
    {
        $ruta = $archivo->getRealPath();
        if ($ruta === false || ! is_readable($ruta) || $archivo->getSize() > self::MAX_BYTES) {
            return null;
        }
        $contenido = file_get_contents($ruta, false, null, 0, self::MAX_BYTES + 1);
        if ($contenido === false || strlen($contenido) > self::MAX_BYTES) {
            return null;
        }
        $paginas = self::contar($contenido);
        if ($paginas === null) {
            $offset = 0;
            $expandidos = 0;
            $cantidad = 0;
            while (preg_match('/stream\r?\n(.*?)\r?\nendstream/s', $contenido, $stream, PREG_OFFSET_CAPTURE, $offset) === 1) {
                if (++$cantidad > self::MAX_STREAMS || $expandidos >= self::MAX_BYTES_EXPANDIDOS) {
                    return null;
                }
                $offset = $stream[0][1] + strlen($stream[0][0]);
                $plano = @gzuncompress($stream[1][0], self::MAX_BYTES_EXPANDIDOS - $expandidos);
                if ($plano !== false) {
                    $expandidos += strlen($plano);
                }
                if ($plano !== false && ($n = self::contar($plano)) !== null) {
                    $paginas = max($paginas ?? 0, $n);
                }
            }
        }

        return $paginas;
    }

    private static function contar(string $contenido): ?int
    {
        if (preg_match_all('#/Type\s*/Pages\b[^>]*?/Count\s+(\d+)|/Count\s+(\d+)[^>]*?/Type\s*/Pages\b#s', $contenido, $m)) {
            $conteos = array_map('intval', array_filter([...$m[1], ...$m[2]], 'strlen'));
            if ($conteos !== []) {
                return max($conteos);
            }
        }

        return null;
    }
}
