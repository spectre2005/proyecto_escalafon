<?php

namespace App\Support\Plataforma;

use App\Models\Plataforma\Institucion;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Carpeta institucional propia en modo unica (PLATAFORMA_ALMACENAMIENTO_UNICA, por ejemplo instituciones/uniq). Se
 * aplica al arrancar (AppServiceProvider), en cada petición, comando y worker.
 *
 * - Vacía (por defecto) o con PLATAFORMA_MODO=subdominio: no hace nada ni consulta la plataforma. Los discos usan las
 *   carpetas comunes, como siempre (instalaciones anteriores, equipos sin base de plataforma y pruebas).
 * - Con valor, en modo unica: reubica solo los discos de ConexionInstitucion::discosPrivados() (documentos,
 *   personal_photos y local; no public ni s3), con la misma regla que ConexionInstitucion. Base, caché y sesiones no
 *   cambian, y no «entra» en la institución (no hay Institucion en el contenedor: las colas y el inventario siguen
 *   como en modo unica).
 *
 * Se establece solo cuando la carpeta de la institución de esta base ya es una carpeta real con sus archivos (después de
 * plataforma:normalizar-almacenamiento), para que el modo unica lea y escriba donde lee y escribe el modo subdominio.
 *
 * Validación, todo o nada: la ruta cumple Institucion::PATRON_ALMACENAMIENTO; la plataforma tiene una institución
 * aprovisionada con esa carpeta y su base_datos es la base de la conexión por defecto (la misma regla que usa
 * ConexionInstitucion: las bases institucionales están en el servidor de esa conexión y se distinguen por el nombre);
 * la carpeta existe, es real (sin enlaces en el camino) y no tiene enlaces simbólicos en su primer nivel (las carpetas
 * de los discos), ni ningún disco sale de ella. Si algo falla, los discos privados quedan inutilizables (cualquier uso
 * lanza una excepción) y se lanza AlmacenamientoUnicaNoValido: la aplicación no arranca, en lugar de escribir en una
 * carpeta común o en la de otra institución.
 */
final class AlmacenamientoUnica
{
    /** Driver inexistente: Storage::disk() lanza una excepción en lugar de leer o escribir. */
    public const DRIVER_BLOQUEADO = 'almacenamiento-no-valido';

    public static function aplicar(): void
    {
        $valor = trim((string) config('plataforma.almacenamiento_unica'));
        if (config('plataforma.modo') !== 'unica' || $valor === '') {
            return;
        }
        try {
            self::validar($valor);
        } catch (AlmacenamientoUnicaNoValido $error) {
            self::bloquear();

            throw $error;
        }
        // Las raíces originales, para que reaplicar (config:cache) o entrar en otra institución no reubique dos veces.
        $servidor = ConexionInstitucion::discosPrivados();
        config([...ConexionInstitucion::reubicarDiscos($valor), 'plataforma.raices_servidor' => $servidor]);
        foreach (array_keys($servidor) as $disco) {
            Storage::forgetDisk($disco);
        }
    }

    /** Motivo por el que $valor no se puede usar en esta instalación, o null. Solo lee: para produccion:verificar. */
    public static function motivo(string $valor): ?string
    {
        try {
            self::validar($valor);
        } catch (AlmacenamientoUnicaNoValido $error) {
            return $error->getMessage();
        }

        return null;
    }

    /**
     * Motivo por el que la carpeta todavía no sirve (ruta, carpeta real, enlaces, discos), o null. No mira la base: para
     * indicar la variable al terminar plataforma:normalizar-almacenamiento --cambiar, que corre en modo subdominio.
     */
    public static function motivoCarpeta(string $valor): ?string
    {
        try {
            self::validarRuta($valor);
            self::validarCarpeta($valor);
        } catch (AlmacenamientoUnicaNoValido $error) {
            return $error->getMessage();
        }

        return null;
    }

    /**
     * En modo unica con la variable vacía: la carpeta de la institución aprovisionada de esta base si ya es una carpeta
     * real con contenido (normalizada) y sin enlaces; si no, o si la plataforma no se puede leer, null. Solo lee.
     */
    public static function carpetaSinConfigurar(): ?string
    {
        if (config('plataforma.modo') !== 'unica' || trim((string) config('plataforma.almacenamiento_unica')) !== '') {
            return null;
        }
        $base = (string) config('database.connections.'.config('database.default').'.database');
        try {
            $institucion = $base === '' ? null : Institucion::query()->where('base_datos', $base)->whereNotNull('aprovisionada_en')->first();
        } catch (Throwable) {
            return null;
        }
        $valor = (string) $institucion?->almacenamiento;
        if ($institucion === null || self::motivoCarpeta($valor) !== null) {
            return null;
        }

        return (scandir(storage_path('app/private/'.$valor)) ?: []) !== ['.', '..'] ? $valor : null;
    }

    private static function validar(string $valor): void
    {
        self::validarRuta($valor);
        self::validarInstitucion($valor);
        self::validarCarpeta($valor);
    }

    private static function validarRuta(string $valor): void
    {
        if (! preg_match(Institucion::PATRON_ALMACENAMIENTO, $valor)) {
            throw new AlmacenamientoUnicaNoValido('no es una carpeta institucional válida (instituciones/{slug}, sin rutas relativas ni absolutas).');
        }
    }

    private static function validarInstitucion(string $valor): void
    {
        try {
            $institucion = Institucion::query()->where('almacenamiento', $valor)->first();
        } catch (Throwable) {
            throw new AlmacenamientoUnicaNoValido('no se pudo leer la base de la plataforma para comprobar a qué institución pertenece la carpeta.');
        }
        $base = (string) config('database.connections.'.config('database.default').'.database');
        match (true) {
            $institucion === null => throw new AlmacenamientoUnicaNoValido("ninguna institución de la plataforma usa la carpeta {$valor}."),
            ! $institucion->aprovisionada() => throw new AlmacenamientoUnicaNoValido("la institución {$institucion->slug} no está aprovisionada."),
            $base === '' || $institucion->base_datos !== $base => throw new AlmacenamientoUnicaNoValido("la carpeta {$valor} es de la institución {$institucion->slug}, y su base no es la de esta instalación (DB_DATABASE)."),
            default => null,
        };
    }

    private static function validarCarpeta(string $valor): void
    {
        $privado = realpath(storage_path('app/private'));
        $carpeta = $privado.'/'.$valor;
        if ($privado === false || is_link($carpeta) || ! is_dir($carpeta) || realpath($carpeta) !== $carpeta) {
            throw new AlmacenamientoUnicaNoValido("la carpeta {$valor} no existe o no es una carpeta real dentro de storage/app/private.");
        }
        foreach (scandir($carpeta) ?: [] as $entrada) {
            if (is_link($carpeta.'/'.$entrada)) {
                throw new AlmacenamientoUnicaNoValido("la carpeta {$valor} tiene enlaces simbólicos (a carpetas comunes u otras): normalízala antes con plataforma:normalizar-almacenamiento.");
            }
        }
        foreach (ConexionInstitucion::reubicarDiscos($valor) as $raiz) {
            $real = file_exists($raiz) ? realpath($raiz) : null;
            if ($real !== null && $real !== $carpeta && ! str_starts_with((string) $real, $carpeta.'/')) {
                throw new AlmacenamientoUnicaNoValido("un disco saldría de la carpeta {$valor}.");
            }
        }
    }

    private static function bloquear(): void
    {
        foreach (array_keys(ConexionInstitucion::discosPrivados()) as $disco) {
            config(["filesystems.disks.{$disco}" => ['driver' => self::DRIVER_BLOQUEADO]]);
            Storage::forgetDisk($disco);
        }
    }
}
