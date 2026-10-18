<?php

namespace App\Support\Plataforma;

use App\Models\Plataforma\Institucion;
use Closure;
use FilesystemIterator;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

/**
 * Inventario de los archivos de una institución, para normalizar su almacenamiento y, más adelante, exportarla. Solo
 * lee: referencias de su base (FUENTES: no todo está en archivos_documento) y archivos físicos, con tamaño y SHA-256
 * (lee el contenido para calcular la huella, nunca lo muestra).
 *
 * Cada referencia (disco y ruta relativa al disco) se busca donde la leería la aplicación:
 * - propia: storage/app/private/{almacenamiento}/{carpeta del disco}/{ruta} (modo subdominio, ConexionInstitucion);
 * - común: storage/app/private/{carpeta del disco}/{ruta} (modo unica, o el destino de un enlace simbólico).
 * Estados: propio (archivo real en su carpeta), enlazado (se llega por un enlace de su carpeta a una carpeta común),
 * comun (solo en la carpeta común: por subdominio no lo ve), faltante y ambiguo (ruta insegura, disco desconocido, o
 * resuelve fuera de storage/app/private o en la carpeta de otra institución).
 *
 * Bloquean una normalización: faltantes, ambiguos, compartidos (el mismo archivo físico lo referencia otra institución
 * o la base principal), alterados (tamaño o SHA-256 distintos de los registrados), copias propia y común distintas,
 * enlaces que no llevan a una carpeta común, huérfanos dentro de una carpeta enlazada (no se sabe de quién son) y bases
 * que no se pudieron leer.
 *
 * El resultado tiene rutas, y las de las fotos llevan el DNI: es privado. resumen() devuelve solo cifras y códigos.
 */
final class InventarioArchivos
{
    /** Columnas con rutas de archivos privados: disco fijo o en otra columna, y tamaño y huella si se registran. */
    public const FUENTES = [
        ['tabla' => 'archivos_documento', 'ruta' => 'storage_path', 'disco_columna' => 'almacenamiento', 'tamano' => 'tamano_bytes', 'sha256' => 'hash_sha256'],
        ['tabla' => 'usuarios', 'ruta' => 'foto_path', 'disco' => 'personal_photos'],
        // PDF anteriores a los folios (Seccion01Controller::descargarPdf), en el disco local.
        ['tabla' => 'certificados_discapacidad', 'ruta' => 'pdf_path', 'disco' => 'local'],
        ['tabla' => 'declaraciones_verificaciones', 'ruta' => 'pdf_path', 'disco' => 'local'],
    ];

    /** Archivos de relleno de git (storage/app/private y fotos-personal): no son de ninguna institución. */
    public const MARCADORES = ['.gitignore'];

    public const BLOQUEOS = ['faltante', 'ambiguo', 'compartido', 'alterado', 'duplicado_distinto', 'enlace_ajeno', 'huerfano_enlazado',
        'bases_no_leidas', 'carpeta_enlazada'];

    private const CONEXION = 'inventario_archivos';

    private string $privado;

    /** @var array<string, string> disco local => su carpeta dentro de storage/app/private ('' para «local») */
    private array $discos = [];

    private function __construct()
    {
        // Las raíces de los discos se leen de la configuración del servidor, no de una institución ya «entrada».
        if (app()->bound(Institucion::class)) {
            throw new RuntimeException('El inventario se hace con la configuración del servidor, fuera de una institución.');
        }
        $privado = storage_path('app/private');
        $this->privado = realpath($privado) ?: throw new RuntimeException('No existe storage/app/private.');
        // Los mismos discos que ConexionInstitucion reubica, con sus raíces del servidor.
        foreach (ConexionInstitucion::discosPrivados() as $nombre => $raiz) {
            $this->discos[$nombre] = trim(substr($raiz, strlen($privado)), '/');
        }
    }

    /** @return array<string, mixed> */
    public static function de(Institucion $institucion): array
    {
        return (new self)->inventariar($institucion);
    }

    /**
     * Solo cifras y códigos, sin rutas: para la salida del comando y la auditoría.
     *
     * @param  array<string, mixed>  $inventario
     * @return array<string, mixed>
     */
    public static function resumen(array $inventario): array
    {
        $referencias = collect($inventario['referencias']);

        return [
            'referencias' => $referencias->count(),
            'por_estado' => $referencias->countBy('estado')->sortKeys()->all(),
            'por_fuente' => $referencias->countBy('fuente')->sortKeys()->all(),
            'marcas' => $referencias->pluck('marcas')->flatten()->countBy()->sortKeys()->all(),
            'bytes' => (int) $referencias->sum('bytes'),
            'enlaces' => collect($inventario['enlaces'])->countBy('tipo')->sortKeys()->all(),
            'huerfanos' => collect($inventario['huerfanos'])->countBy('zona')->sortKeys()->all(),
            'bloqueos' => $inventario['bloqueos'],
            'apto' => $inventario['apto'],
            'huella' => $inventario['huella'],
        ];
    }

    /** @return array<string, mixed> */
    private function inventariar(Institucion $institucion): array
    {
        if (! preg_match(Institucion::PATRON_ALMACENAMIENTO, (string) $institucion->almacenamiento)) {
            throw new RuntimeException('El almacenamiento de la institución no es válido.');
        }
        $propias = $this->leer($institucion->base_datos, fn (ConnectionInterface $db): array => $this->referencias($db));
        $bloqueos = [];
        $ajenas = $this->ajenas($institucion, $bloqueos);

        $referencias = [];
        $usados = [];
        foreach ($propias as $referencia) {
            $ubicacion = $this->ubicar($institucion->almacenamiento, $referencia);
            // relativa: dónde la busca la aplicación dentro de la carpeta de la institución (o de la común).
            $fila = [...$referencia, 'estado' => $ubicacion['estado'], 'motivo' => $ubicacion['motivo'], 'relativa' => $ubicacion['comun'],
                'fisico' => null, 'bytes' => null, 'sha256_actual' => null, 'marcas' => []];
            if ($ubicacion['fisico'] !== null) {
                $clave = self::clave($ubicacion['fisico']);
                $fila = [...$fila, 'fisico' => $this->relativa($ubicacion['fisico']), 'bytes' => (int) filesize($ubicacion['fisico']), 'sha256_actual' => hash_file('sha256', $ubicacion['fisico'])];
                if (isset($ajenas[$clave])) {
                    $fila['marcas'][] = 'compartido';
                }
                if (($referencia['tamano'] !== null && $referencia['tamano'] !== $fila['bytes'])
                    || ($referencia['sha256'] !== null && ! hash_equals($referencia['sha256'], $fila['sha256_actual']))) {
                    $fila['marcas'][] = 'alterado';
                }
                // Copia propia y, además, otra en la carpeta común (p. ej. de un tiempo en modo unica).
                $comun = $ubicacion['estado'] === 'propio' ? realpath($this->privado.'/'.$ubicacion['comun']) : false;
                if ($comun !== false && is_file($comun) && self::clave($comun) !== $clave) {
                    $fila['marcas'][] = hash_file('sha256', $comun) === $fila['sha256_actual'] ? 'duplicado_identico' : 'duplicado_distinto';
                }
                $usados[$clave] = true;
            }
            $referencias[] = $fila;
        }

        [$enlaces, $huerfanos] = $this->recorrer($institucion, $usados, $ajenas, $bloqueos);

        foreach ($referencias as $fila) {
            foreach ([$fila['estado'], ...$fila['marcas']] as $codigo) {
                if (in_array($codigo, self::BLOQUEOS, true)) {
                    $bloqueos[$codigo] = ($bloqueos[$codigo] ?? 0) + 1;
                }
            }
        }
        foreach ($enlaces as $enlace) {
            if ($enlace['tipo'] === 'ajeno') {
                $bloqueos['enlace_ajeno'] = ($bloqueos['enlace_ajeno'] ?? 0) + 1;
            }
        }
        foreach ($huerfanos as $huerfano) {
            if ($huerfano['zona'] === 'enlazada') {
                $bloqueos['huerfano_enlazado'] = ($bloqueos['huerfano_enlazado'] ?? 0) + 1;
            }
        }
        ksort($bloqueos);

        return ['institucion' => $institucion->slug, 'almacenamiento' => $institucion->almacenamiento, 'medido_en' => now()->toIso8601String(),
            'referencias' => $referencias, 'enlaces' => $enlaces, 'huerfanos' => $huerfanos, 'bloqueos' => $bloqueos, 'apto' => $bloqueos === [],
            'huella' => self::huella($referencias, $enlaces, $huerfanos)];
    }

    /** @return list<array{fuente: string, disco: string, ruta: string, tamano: ?int, sha256: ?string}> */
    private function referencias(ConnectionInterface $db): array
    {
        $referencias = [];
        foreach (self::FUENTES as $fuente) {
            $columnas = array_values(array_filter([$fuente['ruta'], $fuente['disco_columna'] ?? null, $fuente['tamano'] ?? null, $fuente['sha256'] ?? null]));
            foreach ($db->table($fuente['tabla'])->whereNotNull($fuente['ruta'])->where($fuente['ruta'], '<>', '')->get($columnas) as $fila) {
                $referencias[] = [
                    'fuente' => $fuente['tabla'],
                    'disco' => (string) (isset($fuente['disco_columna']) ? $fila->{$fuente['disco_columna']} : $fuente['disco']),
                    'ruta' => (string) $fila->{$fuente['ruta']},
                    'tamano' => isset($fuente['tamano']) ? (int) $fila->{$fuente['tamano']} : null,
                    'sha256' => isset($fuente['sha256']) && $fila->{$fuente['sha256']} !== null ? strtolower(trim((string) $fila->{$fuente['sha256']})) : null,
                ];
            }
        }

        return $referencias;
    }

    /**
     * Archivos físicos (dispositivo:inodo) que referencian las demás instituciones aprovisionadas y la base principal
     * (la del .env, si no es de ninguna institución: sus archivos están en las carpetas comunes).
     *
     * @param  array<string, int>  $bloqueos
     * @return array<string, true>
     */
    private function ajenas(Institucion $institucion, array &$bloqueos): array
    {
        $duenos = [];
        foreach (Institucion::query()->whereNotNull('aprovisionada_en')->whereKeyNot($institucion->id)->get() as $otra) {
            $duenos[] = [$otra->base_datos, $otra->almacenamiento];
        }
        $principal = (string) config('database.connections.'.config('database.default').'.database');
        if ($principal !== '' && ! Institucion::query()->where('base_datos', $principal)->exists()) {
            $duenos[] = [null, null];
        }
        $ajenas = [];
        foreach ($duenos as [$base, $almacenamiento]) {
            try {
                $referencias = $base === null ? $this->referencias(DB::connection()) : $this->leer($base, fn (ConnectionInterface $db): array => $this->referencias($db));
            } catch (Throwable) {
                $bloqueos['bases_no_leidas'] = ($bloqueos['bases_no_leidas'] ?? 0) + 1;

                continue;
            }
            foreach ($referencias as $referencia) {
                // Su archivo propio y el de la carpeta común: los dos podrían coincidir con uno de esta institución.
                foreach ($almacenamiento === null ? [null] : [$almacenamiento, null] as $donde) {
                    $ubicacion = $this->ubicar($donde, $referencia);
                    if ($ubicacion['fisico'] !== null) {
                        $ajenas[self::clave($ubicacion['fisico'])] = true;
                    }
                }
            }
        }

        return $ajenas;
    }

    /**
     * Dónde está el archivo de una referencia para una institución (o, con $almacenamiento null, para la base
     * principal, que usa las carpetas comunes).
     *
     * @param  array{disco: string, ruta: string}  $referencia
     * @return array{estado: string, motivo: ?string, fisico: ?string, comun: ?string}
     */
    private function ubicar(?string $almacenamiento, array $referencia): array
    {
        $resultado = fn (string $estado, ?string $motivo = null, ?string $fisico = null, ?string $comun = null): array => compact('estado', 'motivo', 'fisico', 'comun');
        $carpeta = $this->discos[$referencia['disco']] ?? null;
        $ruta = self::segura($referencia['ruta']);
        if ($carpeta === null) {
            return $resultado('ambiguo', 'disco_desconocido');
        }
        if ($ruta === null) {
            return $resultado('ambiguo', 'ruta_insegura');
        }
        $comun = ltrim($carpeta.'/'.$ruta, '/');
        // Con el disco «local», una ruta podría apuntar a la zona de instituciones o a una carpeta oculta.
        if (str_starts_with($comun, 'instituciones/') || str_starts_with($comun, '.')) {
            return $resultado('ambiguo', 'ruta_de_otra_zona');
        }
        if ($almacenamiento !== null) {
            $real = realpath($this->privado.'/'.$almacenamiento.'/'.$comun);
            if ($real !== false) {
                if (! is_file($real)) {
                    return $resultado('ambiguo', 'no_es_archivo');
                }
                $zona = $this->zona($real);
                $enlace = $this->pasaPorEnlace($almacenamiento, $comun);

                return match (true) {
                    $zona === $almacenamiento && ! $enlace => $resultado('propio', null, $real, $comun),
                    $zona === 'comun' && $enlace => $resultado('enlazado', null, $real, $comun),
                    $zona === 'fuera' => $resultado('ambiguo', 'fuera_de_storage'),
                    $zona === 'comun' || $zona === $almacenamiento => $resultado('ambiguo', 'enlace_interno'),
                    default => $resultado('ambiguo', 'otra_institucion'),
                };
            }
        }
        $real = realpath($this->privado.'/'.$comun);
        if ($real === false) {
            return $resultado('faltante', null, null, $comun);
        }

        return $this->zona($real) === 'comun' && is_file($real) ? $resultado('comun', null, $real, $comun) : $resultado('ambiguo', 'fuera_de_lugar');
    }

    /**
     * Enlaces y archivos no referenciados de su carpeta (sin seguir enlaces), de las carpetas comunes a las que
     * enlaza y del resto de carpetas comunes (archivos que podrían ser de modo unica).
     *
     * @param  array<string, true>  $usados
     * @param  array<string, true>  $ajenas
     * @param  array<string, int>  $bloqueos
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function recorrer(Institucion $institucion, array $usados, array $ajenas, array &$bloqueos): array
    {
        $enlaces = [];
        $huerfanos = [];
        $carpeta = $this->privado.'/'.$institucion->almacenamiento;
        $enlazadas = [];
        if (is_link($carpeta)) {
            $bloqueos['carpeta_enlazada'] = 1;
        } elseif (is_dir($carpeta)) {
            $this->cadaEntrada($carpeta, function (\SplFileInfo $entrada) use ($carpeta, &$enlaces, &$enlazadas, &$huerfanos, $usados): void {
                $ruta = $entrada->getPathname();
                if ($entrada->isLink()) {
                    $destino = realpath($ruta);
                    $comun = $destino !== false && is_dir($destino) && $this->zona($destino) === 'comun' && dirname($ruta) === $carpeta;
                    $enlaces[] = ['ruta' => $this->relativa($ruta), 'destino' => $destino === false ? null : $this->relativa($destino), 'tipo' => $comun ? 'comun' : 'ajeno'];
                    if ($comun) {
                        $enlazadas[$destino] = true;
                    }
                } elseif ($entrada->isFile() && ! in_array($entrada->getFilename(), self::MARCADORES, true) && ! isset($usados[self::clave($ruta)])) {
                    $huerfanos[] = ['ruta' => $this->relativa($ruta), 'zona' => 'propia', 'bytes' => $entrada->getSize(), 'sha256' => hash_file('sha256', $ruta)];
                }
            });
        }
        // Carpetas comunes: las enlazadas (sus huérfanos bloquean) y las demás (solo informan).
        $comunes = [];
        foreach (scandir($this->privado) ?: [] as $nombre) {
            $ruta = $this->privado.'/'.$nombre;
            if ($nombre !== 'instituciones' && ! str_starts_with($nombre, '.') && is_dir($ruta) && ! is_link($ruta)) {
                $comunes[realpath($ruta)] = true;
            }
        }
        foreach (array_keys($enlazadas + $comunes) as $comun) {
            $zona = isset($enlazadas[$comun]) ? 'enlazada' : 'comun';
            $this->cadaEntrada($comun, function (\SplFileInfo $entrada) use ($zona, &$huerfanos, &$enlaces, $usados, $ajenas): void {
                $ruta = $entrada->getPathname();
                if ($entrada->isLink()) {
                    // Un enlace dentro de una carpeta común: no se sabe a quién lleva.
                    $enlaces[] = ['ruta' => $this->relativa($ruta), 'destino' => null, 'tipo' => 'ajeno'];

                    return;
                }
                if (! $entrada->isFile() || in_array($entrada->getFilename(), self::MARCADORES, true) || isset($usados[self::clave($ruta)])) {
                    return;
                }
                $huerfanos[] = ['ruta' => $this->relativa($ruta), 'zona' => isset($ajenas[self::clave($ruta)]) ? 'ajeno' : $zona, 'bytes' => $entrada->getSize(), 'sha256' => null];
            });
        }

        return [$enlaces, $huerfanos];
    }

    /** Recorre una carpeta sin seguir enlaces simbólicos (los entrega como entradas, sin entrar en ellos). */
    private function cadaEntrada(string $carpeta, Closure $visitar): void
    {
        $recorrido = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($carpeta, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($recorrido as $entrada) {
            $visitar($entrada);
        }
    }

    /** Zona de una ruta real: 'fuera' de storage/app/private, la carpeta de una institución o 'comun'. */
    private function zona(string $real): string
    {
        if (! str_starts_with($real, $this->privado.'/')) {
            return 'fuera';
        }
        $partes = explode('/', substr($real, strlen($this->privado) + 1));

        return $partes[0] === 'instituciones' ? 'instituciones/'.($partes[1] ?? '') : 'comun';
    }

    /** Si algún tramo de la ruta, dentro de la carpeta de la institución, es un enlace simbólico. */
    private function pasaPorEnlace(string $almacenamiento, string $ruta): bool
    {
        $actual = $this->privado.'/'.$almacenamiento;
        foreach (explode('/', $ruta) as $parte) {
            $actual .= '/'.$parte;
            if (is_link($actual)) {
                return true;
            }
        }

        return false;
    }

    private function relativa(string $ruta): string
    {
        return str_starts_with($ruta, $this->privado.'/') ? substr($ruta, strlen($this->privado) + 1) : $ruta;
    }

    /** Ruta relativa segura: sin «..», «.», tramos vacíos, barras invertidas, NUL ni barra inicial. */
    public static function segura(string $ruta): ?string
    {
        if ($ruta === '' || strlen($ruta) > 1000 || str_contains($ruta, "\0") || str_contains($ruta, '\\') || str_starts_with($ruta, '/')) {
            return null;
        }
        foreach (explode('/', $ruta) as $parte) {
            if ($parte === '' || $parte === '.' || $parte === '..') {
                return null;
            }
        }

        return $ruta;
    }

    private function leer(string $base, Closure $leer): mixed
    {
        $plantilla = (array) config('database.connections.'.config('database.default'));
        config(['database.connections.'.self::CONEXION => [...$plantilla, 'database' => $base]]);
        try {
            return $leer(DB::connection(self::CONEXION));
        } finally {
            DB::purge(self::CONEXION);
        }
    }

    /** Identidad del archivo físico: dispositivo e inodo (dos rutas, o un enlace, al mismo archivo dan la misma). */
    private static function clave(string $ruta): string
    {
        $datos = stat($ruta);

        return $datos['dev'].':'.$datos['ino'];
    }

    /** Huella del inventario: cambia si cambia cualquier referencia, archivo, enlace o huérfano propio. */
    private static function huella(array $referencias, array $enlaces, array $huerfanos): string
    {
        $lineas = [];
        foreach ($referencias as $r) {
            $lineas[] = implode("\0", ['r', $r['fuente'], $r['disco'], $r['ruta'], $r['estado'], (string) $r['fisico'], (string) $r['sha256_actual']]);
        }
        foreach ($enlaces as $e) {
            $lineas[] = implode("\0", ['e', $e['ruta'], (string) $e['destino'], $e['tipo']]);
        }
        foreach ($huerfanos as $h) {
            $lineas[] = implode("\0", ['h', $h['ruta'], $h['zona'], (string) $h['bytes'], (string) $h['sha256']]);
        }
        sort($lineas);

        return hash('sha256', implode("\n", $lineas));
    }
}
