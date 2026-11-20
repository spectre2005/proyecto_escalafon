<?php

namespace App\Support\Documentos;

use Illuminate\Http\UploadedFile;

/**
 * Comprobaciones del PDF en el servidor: tipo real por contenido (no por extensión ni por el
 * Content-Type que manda el navegador), hash SHA-256 y número de páginas.
 */
final class ArchivoPdf
{
    /**
     * El contenido tiene la cabecera %PDF-x.y (al inicio o al comienzo de una línea dentro de los primeros 1024 bytes,
     * como permite la norma) y finfo lo reconoce como PDF.
     */
    public static function esPdf(UploadedFile $archivo): bool
    {
        $ruta = $archivo->getRealPath();
        if ($ruta === false || ! is_readable($ruta)) {
            return false;
        }
        $inicio = (string) file_get_contents($ruta, false, null, 0, 1024);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($ruta);

        return self::tieneCabecera($inicio) && $mime === 'application/pdf';
    }

    /** Cabecera %PDF-x.y en los primeros 1024 bytes, al inicio o tras un salto de línea (no en medio de un texto). */
    public static function tieneCabecera(string $contenido): bool
    {
        return preg_match('/(?:\A|[\r\n])%PDF-\d\.\d/', substr($contenido, 0, 1024)) === 1;
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

    /** Nodos /Pages que se examinan como máximo: un PDF real tiene pocos; uno manipulado podría tener miles. */
    private const MAX_NODOS = 200;

    /** Distancia máxima (bytes) que se recorre hacia atrás y hacia adelante para delimitar un diccionario. */
    private const MAX_RECORRIDO = 8192;

    /**
     * Busca cada /Type /Pages, toma el diccionario << … >> que lo contiene (respetando diccionarios anidados como
     * /Resources << … >>) y lee su /Count de primer nivel. Con límites de nodos y de recorrido, el costo es lineal: un
     * archivo manipulado no puede ocupar el servidor (antes crecía al cuadrado del tamaño).
     */
    private static function contar(string $contenido): ?int
    {
        if (! preg_match_all('#/Type\s*/Pages\b#', $contenido, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $conteos = [];
        foreach (array_slice($m[0], 0, self::MAX_NODOS) as [, $posicion]) {
            $diccionario = self::diccionarioEn($contenido, $posicion);
            if ($diccionario === null) {
                continue;
            }
            // Quita los diccionarios anidados para leer solo el /Count del nodo /Pages.
            do {
                $diccionario = preg_replace('/<<(?:(?!<<|>>).)*>>/s', ' ', $diccionario, -1, $reemplazos);
            } while ($reemplazos > 0);
            if (preg_match('#/Count\s+(\d+)#', $diccionario, $conteo)) {
                $conteos[] = (int) $conteo[1];
            }
        }

        return $conteos === [] ? null : max($conteos);
    }

    /** Interior (sin los << >> externos) del diccionario que contiene la posición dada, o null si no se puede delimitar. */
    private static function diccionarioEn(string $contenido, int $posicion): ?string
    {
        $profundidad = 0;
        $inicio = null;
        $limiteAtras = max(0, $posicion - self::MAX_RECORRIDO);
        for ($i = $posicion - 1; $i > $limiteAtras; $i--) {
            $par = $contenido[$i - 1].$contenido[$i];
            if ($par === '>>') {
                $profundidad++;
                $i--;
            } elseif ($par === '<<') {
                if ($profundidad === 0) {
                    $inicio = $i + 1;
                    break;
                }
                $profundidad--;
                $i--;
            }
        }
        if ($inicio === null) {
            return null;
        }
        $profundidad = 0;
        $largo = min(strlen($contenido), $inicio + self::MAX_RECORRIDO);
        for ($i = $inicio; $i < $largo - 1; $i++) {
            $par = $contenido[$i].$contenido[$i + 1];
            if ($par === '<<') {
                $profundidad++;
                $i++;
            } elseif ($par === '>>') {
                if ($profundidad === 0) {
                    return substr($contenido, $inicio, $i - $inicio);
                }
                $profundidad--;
                $i++;
            }
        }

        return null;
    }
}
