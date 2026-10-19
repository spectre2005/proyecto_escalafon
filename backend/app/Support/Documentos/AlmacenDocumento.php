<?php

namespace App\Support\Documentos;

use App\Models\ArchivoDocumento;
use App\Support\AuditoriaInstitucional;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final class AlmacenDocumento
{
    public static function cifrar(string $contenido): string
    {
        return self::encrypter()->encryptString($contenido);
    }

    public static function leer(ArchivoDocumento $archivo): string
    {
        abort_if($archivo->estado_antivirus === 'infectado', 423, 'El archivo está bloqueado por el antivirus.');
        $contenido = Storage::disk($archivo->almacenamiento)->get($archivo->storage_path);
        abort_unless(is_string($contenido), 404, 'El archivo no está disponible.');
        if ($archivo->cifrado) {
            try {
                $contenido = self::encrypter()->decryptString($contenido);
            } catch (DecryptException) {
                throw new HttpException(503, 'No se pudo verificar el archivo cifrado. Contacta con el administrador.');
            }
        }
        if ($archivo->estado_antivirus !== 'limpio') {
            try {
                app(AnalizadorAntivirus::class)->analizar($contenido);
            } catch (ValidationException $error) {
                $archivo->update(['estado_antivirus' => 'infectado']);
                throw new HttpException(423, 'El archivo está bloqueado por el antivirus.', $error);
            }
            $archivo->update(['estado_antivirus' => 'limpio']);
        }

        return $contenido;
    }

    public static function leerAnterior(string $contenido): string
    {
        if (str_starts_with($contenido, 'ESC-PDF1:')) {
            try {
                $contenido = self::encrypter()->decryptString(substr($contenido, 9));
            } catch (DecryptException) {
                throw new HttpException(503, 'No se pudo verificar el archivo anterior protegido.');
            }
        }
        app(AnalizadorAntivirus::class)->analizar($contenido);

        return $contenido;
    }

    public static function cifrarAnterior(string $contenido): string
    {
        return 'ESC-PDF1:'.self::cifrar($contenido);
    }

    public static function proteger(ArchivoDocumento $archivo): bool
    {
        $nueva = null;
        $anterior = null;
        try {
            $cambiado = DB::transaction(function () use ($archivo, &$nueva, &$anterior): bool {
                $registro = ArchivoDocumento::query()->lockForUpdate()->findOrFail($archivo->id);
                $contenido = self::leer($registro);
                if ($registro->cifrado) {
                    return false;
                }
                $anterior = $registro->storage_path;
                $nueva = $anterior.'.'.Str::uuid().'.enc';
                $disco = Storage::disk($registro->almacenamiento);
                abort_unless($disco->put($nueva, self::cifrar($contenido)), 503, 'No se pudo guardar la copia protegida.');
                $verificacion = clone $registro;
                $verificacion->forceFill(['storage_path' => $nueva, 'cifrado' => true]);
                abort_unless(hash_equals(hash('sha256', $contenido), hash('sha256', self::leer($verificacion))), 503, 'No se pudo verificar la copia protegida.');
                $antes = $registro->getAttributes();
                $registro->forceFill(['storage_path' => $nueva, 'cifrado' => true, 'ruta_sin_cifrar_pendiente' => $anterior])->save();
                AuditoriaInstitucional::registrar('archivos_documento', $registro->id, 'archivo.cifrado', null, null, $antes, $registro->getAttributes());

                return true;
            });
        } catch (Throwable $error) {
            if ($nueva !== null) {
                Storage::disk($archivo->almacenamiento)->delete($nueva);
            }
            if ($error instanceof HttpException && $error->getStatusCode() === 423) {
                $archivo->update(['estado_antivirus' => 'infectado']);
            }
            throw $error;
        }
        $pendiente = $archivo->refresh()->ruta_sin_cifrar_pendiente;
        if ($pendiente !== null) {
            $disco = Storage::disk($archivo->almacenamiento);
            abort_if($pendiente === $archivo->storage_path || ! $archivo->cifrado, 503, 'La copia anterior requiere revisión antes de retirarse.');
            self::leer($archivo);
            abort_if($disco->exists($pendiente) && ! $disco->delete($pendiente), 503, 'La copia cifrada está disponible, pero queda retirar la copia anterior del almacenamiento.');
            $archivo->forceFill(['ruta_sin_cifrar_pendiente' => null])->save();
        }

        return $cambiado;
    }

    private static function clave(string $valor): string
    {
        $clave = str_starts_with($valor, 'base64:') ? base64_decode(substr($valor, 7), true) : false;
        if ($clave === false || strlen($clave) !== 32) {
            throw new HttpException(503, 'La clave de protección de documentos no está configurada correctamente.');
        }

        return $clave;
    }

    private static function encrypter(): Encrypter
    {
        $clave = config('documentos.clave');
        if (! $clave) {
            $ruta = (string) config('documentos.archivo_clave');
            $clave = is_readable($ruta) ? trim((string) file_get_contents($ruta)) : '';
        }
        $encrypter = new Encrypter(self::clave((string) $clave), 'AES-256-GCM');
        $encrypter->previousKeys(array_map(self::clave(...), config('documentos.claves_anteriores', [])));

        return $encrypter;
    }
}
