<?php

namespace App\Support\Documentos;

use Illuminate\Http\UploadedFile;

/**
 * Comprobaciones del PDF en el servidor: tipo real por contenido (no por extensión ni por el
 * Content-Type que manda el navegador), hash SHA-256 y número de páginas.
 */
final class ArchivoPdf
{
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
        $contenido = (string) file_get_contents($archivo->getRealPath());
        $paginas = self::contar($contenido);
        if ($paginas === null && preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $contenido, $streams)) {
            foreach ($streams[1] as $stream) {
                $plano = @gzuncompress($stream);
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
