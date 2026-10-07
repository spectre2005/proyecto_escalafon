<?php

namespace App\Support;

use App\Models\DocumentoUsuario;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** Lee la evidencia sin editar documentos, archivos ni estados de revisión. */
final class VerificacionRegistroDocumento
{
    public const ESTADOS_REGISTRADOS = ['pendiente', 'cargado', 'aceptado', 'observado', 'en_fiscalizacion', 'verificado'];

    /** @return list<string> */
    public static function errores(DocumentoUsuario $documento): array
    {
        $errores = [];
        if (! in_array($documento->estado, self::ESTADOS_REGISTRADOS, true)) {
            $errores[] = 'El documento está anulado o no tiene un estado de registro válido. Registra una nueva evidencia.';
        }
        if (! $documento->folio_inicio || $documento->folio_inicio < 1 || $documento->folio_fin < $documento->folio_inicio) {
            $errores[] = 'Falta una foliación válida. Revisa la carga en su sección.';
        }
        $archivo = $documento->archivo;
        $disco = (string) config('legajo.disco_documentos');
        if (! $archivo || $archivo->almacenamiento !== $disco || ! $archivo->storage_path) {
            return [...$errores, 'Falta el archivo privado del documento. Adjunta la evidencia desde su sección.'];
        }
        if (! $archivo->estado_antivirus || $archivo->estado_antivirus === 'infectado') {
            $errores[] = 'Falta el estado de antivirus o el archivo está bloqueado. Solicita su revisión y registra una evidencia válida.';
        }
        if (! preg_match('/^[a-f0-9]{64}$/i', (string) $archivo->hash_sha256)) {
            $errores[] = 'Falta un hash válido del archivo. Solicita a soporte revisar la carga.';
        }
        try {
            if (! Storage::disk($disco)->exists($archivo->storage_path)) {
                $errores[] = 'El archivo no está disponible. Solicita a soporte revisar su almacenamiento.';
            } else {
                $stream = Storage::disk($disco)->readStream($archivo->storage_path);
                if (! is_resource($stream)) {
                    $errores[] = 'No se pudo leer el archivo. Reintenta la verificación.';
                } else {
                    try {
                        $hash = hash_init('sha256');
                        hash_update_stream($hash, $stream);
                        if (! hash_equals(strtolower((string) $archivo->hash_sha256), hash_final($hash))) {
                            $errores[] = 'El archivo no coincide con su hash. Solicita a soporte verificar su integridad.';
                        }
                    } finally {
                        fclose($stream);
                    }
                }
            }
        } catch (Throwable) {
            $errores[] = 'No se pudo consultar el almacenamiento. Reintenta la verificación cuando esté disponible.';
        }

        return $errores;
    }
}
