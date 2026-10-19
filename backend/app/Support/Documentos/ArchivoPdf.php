<?php

namespace App\Support\Documentos;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

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

    /** Poppler analiza el árbol PDF real, incluidos objetos y flujos comprimidos. */
    public static function paginas(UploadedFile $archivo): int
    {
        $binario = (new ExecutableFinder)->find((string) config('documentos.pdfinfo_binario', 'pdfinfo'));
        if ($binario === null) {
            throw new HttpException(503, 'El verificador de PDF no está disponible. Revisa la instalación de Poppler.');
        }
        $proceso = new Process([$binario, '-enc', 'UTF-8', $archivo->getRealPath()], env: ['LC_ALL' => 'C']);
        $proceso->setTimeout(10);
        try {
            $proceso->run();
        } catch (ProcessTimedOutException) {
            throw ValidationException::withMessages(['pdf' => 'No se pudo analizar el PDF dentro del tiempo permitido. Guarda una copia simplificada e intenta nuevamente.']);
        }
        $salida = $proceso->getOutput();
        if (! $proceso->isSuccessful() || preg_match('/^Encrypted:\s+yes/im', $salida)
            || ! preg_match('/^Pages:\s+(\d+)\s*$/m', $salida, $paginas) || (int) $paginas[1] < 1) {
            throw ValidationException::withMessages(['pdf' => 'No se pudo verificar el PDF. Debe ser un documento válido, sin contraseña ni cifrado interno.']);
        }

        return (int) $paginas[1];
    }
}
