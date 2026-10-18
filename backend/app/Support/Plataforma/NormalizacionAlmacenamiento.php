<?php

namespace App\Support\Plataforma;

use App\Models\Plataforma\Institucion;
use Closure;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * Normaliza el almacenamiento de una institución: sus carpetas de primer nivel que hoy son enlaces simbólicos a
 * carpetas comunes, o que no tienen los archivos que su base referencia en las carpetas comunes (modo unica), pasan a
 * ser carpetas reales con los mismos archivos y las mismas rutas relativas. La base no cambia.
 *
 * Etapas, cada una aparte (plataforma:normalizar-almacenamiento):
 * 1. preparar: inventario sin bloqueos y copia en storage/app/normalizacion/{slug}-{fecha}/preparacion, fuera de
 *    instituciones/ y de todo respaldo. Nunca sobrescribe y nunca escribe en los originales.
 * 2. verificar: cada archivo copiado con su tamaño y SHA-256, nada de más, y el inventario igual al de la preparación.
 * 3. cambiar: por carpeta, aparta la actual (el enlace o la carpeta) en anteriores/ y pone la preparada en su lugar,
 *    con rename (mismo sistema de archivos). Luego comprueba el inventario; si algo no coincide, revierte solo.
 * 4. revertir: devuelve cada carpeta a su lugar mirando lo que hay en el disco (sirve tras una interrupción). No
 *    revierte si la carpeta normalizada ya tiene archivos que no estaban en la preparación.
 * Los originales de las carpetas comunes no se tocan nunca: quitarlos es otra decisión.
 *
 * Toda etapa exige PLATAFORMA_MODO=subdominio (en modo unica los discos usan las carpetas comunes: normalizar
 * dividiría los archivos en dos lugares) y la institución suspendida (nadie escribe mientras tanto).
 *
 * manifiesto.json lleva rutas (las de las fotos, con DNI): carpeta 0700, archivos 0600, fuera de git, del panel y de la
 * auditoría. estado.json es el diario de la etapa en curso.
 */
final class NormalizacionAlmacenamiento
{
    public const CARPETA = 'app/normalizacion';

    public const PATRON_PREPARACION = '/^[a-z0-9-]{3,40}-\d{8}-\d{6}$/';

    /** Margen de espacio libre sobre lo que se copia. */
    private const MARGEN_ESPACIO = 2;

    public static function raiz(): string
    {
        return storage_path(self::CARPETA);
    }

    /** Motivo por el que una etapa no puede empezar, o null. */
    public static function motivoNoNormalizable(Institucion $institucion): ?string
    {
        return match (true) {
            config('plataforma.modo') !== 'subdominio' => 'Con PLATAFORMA_MODO=unica no se normaliza: la aplicación seguiría usando las carpetas comunes y los archivos quedarían en dos lugares.',
            ! $institucion->aprovisionada() => 'La institución no está aprovisionada.',
            $institucion->estado !== Institucion::SUSPENDIDA => 'Suspende la institución antes: nadie debe subir ni reemplazar archivos durante la normalización.',
            ! preg_match(Institucion::PATRON_ALMACENAMIENTO, (string) $institucion->almacenamiento) => 'El almacenamiento de la institución no es válido.',
            default => null,
        };
    }

    /**
     * Qué tendrá cada carpeta de primer nivel de la institución que hay que normalizar: sus archivos reales (si ya es
     * una carpeta) y los que hoy lee de una carpeta común. Sin rutas en la salida pública: solo para el manifiesto.
     *
     * @param  array<string, mixed>  $inventario
     * @return array<string, array{actual: string, archivos: array<string, array{origen: string, bytes: int, sha256: string}>}>
     */
    public static function plan(array $inventario): array
    {
        $privado = storage_path('app/private');
        $propia = $privado.'/'.$inventario['almacenamiento'];
        $carpetas = [];
        foreach ($inventario['enlaces'] as $enlace) {
            if ($enlace['tipo'] === 'comun') {
                $carpetas[basename($enlace['ruta'])] = true;
            }
        }
        foreach ($inventario['referencias'] as $referencia) {
            if (in_array($referencia['estado'], ['enlazado', 'comun'], true)) {
                $carpetas[explode('/', $referencia['relativa'])[0]] = true;
            }
        }
        $plan = [];
        foreach (array_keys($carpetas) as $carpeta) {
            $actual = $propia.'/'.$carpeta;
            $plan[$carpeta] = ['actual' => is_link($actual) ? 'enlace' : (is_dir($actual) ? 'carpeta' : 'no_existe'), 'archivos' => []];
            if ($plan[$carpeta]['actual'] === 'carpeta') {
                foreach (self::archivos($actual) as $relativa => $ruta) {
                    $plan[$carpeta]['archivos'][$relativa] = ['origen' => substr($ruta, strlen($privado) + 1), 'bytes' => (int) filesize($ruta), 'sha256' => hash_file('sha256', $ruta)];
                }
            }
        }
        foreach ($inventario['referencias'] as $referencia) {
            if (! in_array($referencia['estado'], ['enlazado', 'comun'], true)) {
                continue;
            }
            [$carpeta, $relativa] = explode('/', $referencia['relativa'], 2) + [1 => ''];
            $plan[$carpeta]['archivos'][$relativa] = ['origen' => $referencia['fisico'], 'bytes' => $referencia['bytes'], 'sha256' => $referencia['sha256_actual']];
        }
        ksort($plan);

        return $plan;
    }

    /**
     * Etapa 1. Devuelve el nombre de la preparación.
     *
     * @param  Closure(string $carpeta, int $copiados): void|null  $avance  tras cada archivo copiado
     */
    public static function preparar(Institucion $institucion, ?Closure $avance = null): string
    {
        self::exigir($institucion);
        $inventario = InventarioArchivos::de($institucion);
        if (! $inventario['apto']) {
            throw new NormalizacionRechazada('El inventario tiene bloqueos ('.self::codigos($inventario['bloqueos']).'): no se preparó nada.');
        }
        $plan = self::plan($inventario);
        if ($plan === []) {
            throw new NormalizacionRechazada('No hay nada que normalizar: la institución no usa enlaces ni carpetas comunes.');
        }
        $bytes = collect($plan)->sum(fn (array $c): int => collect($c['archivos'])->sum('bytes'));
        $raiz = self::raiz();
        if (! is_dir($raiz) && ! @mkdir($raiz, 0700, true)) {
            throw new NormalizacionRechazada('No se pudo crear la carpeta de normalización.');
        }
        @chmod($raiz, 0700);
        $propia = storage_path('app/private/'.$institucion->almacenamiento);
        if (! is_dir($propia) && ! @mkdir($propia, 0775, true)) {
            throw new NormalizacionRechazada('No se pudo crear la carpeta de la institución.');
        }
        if (stat($raiz)['dev'] !== stat($propia)['dev']) {
            throw new NormalizacionRechazada('La carpeta de normalización y la de la institución están en sistemas de archivos distintos: el cambio no sería atómico.');
        }
        $libre = @disk_free_space($raiz);
        if ($libre === false || $libre < $bytes * self::MARGEN_ESPACIO) {
            throw new NormalizacionRechazada('No hay espacio libre suficiente (se pide el doble de lo que se copia).');
        }
        $nombre = $institucion->slug.'-'.now()->format('Ymd-His');
        $carpeta = $raiz.'/'.$nombre;
        if (file_exists($carpeta) || is_link($carpeta) || ! @mkdir($carpeta, 0700)) {
            throw new NormalizacionRechazada('Ya existe una preparación con ese nombre: no se sobrescribe. Vuelve a intentarlo en un segundo.');
        }
        foreach (['preparacion', 'anteriores'] as $sub) {
            mkdir($carpeta.'/'.$sub, 0700);
        }
        $referencias = [];
        foreach ($inventario['referencias'] as $r) {
            $referencias[self::claveReferencia($r)] = $r['sha256_actual'];
        }
        self::escribir($carpeta.'/manifiesto.json', ['version' => 1, 'institucion_id' => $institucion->id, 'slug' => $institucion->slug,
            'almacenamiento' => $institucion->almacenamiento, 'creado_en' => now()->toIso8601String(), 'huella_inventario' => $inventario['huella'],
            'referencias' => $referencias, 'plan' => $plan, 'archivos' => collect($plan)->sum(fn (array $c): int => count($c['archivos'])), 'bytes' => $bytes]);
        self::estado($carpeta, 'preparando');
        try {
            $copiados = 0;
            foreach ($plan as $nombreCarpeta => $contenido) {
                @mkdir($carpeta.'/preparacion/'.$nombreCarpeta, 0700);
                foreach ($contenido['archivos'] as $relativa => $archivo) {
                    self::copiar(storage_path('app/private/'.$archivo['origen']), $carpeta.'/preparacion/'.$nombreCarpeta.'/'.$relativa, $archivo);
                    $copiados++;
                    if ($avance) {
                        $avance($nombreCarpeta, $copiados);
                    }
                }
            }
        } catch (Throwable $error) {
            self::estado($carpeta, 'preparacion_fallida');
            throw $error instanceof NormalizacionRechazada ? $error : new NormalizacionRechazada('La copia de preparación se interrumpió: no se cambió nada y los originales están intactos.', 0, $error);
        }
        self::estado($carpeta, 'preparada');

        return $nombre;
    }

    /**
     * Etapa 2. Lanza NormalizacionRechazada si algo no coincide.
     *
     * @return array<string, mixed> el manifiesto
     */
    public static function verificar(Institucion $institucion, string $preparacion): array
    {
        self::exigir($institucion);
        [$carpeta, $manifiesto] = self::abrir($institucion, $preparacion);
        $etapa = self::leerEstado($carpeta)['etapa'] ?? null;
        if (! in_array($etapa, ['preparada', 'verificada', 'verificacion_fallida'], true)) {
            throw new NormalizacionRechazada("Esta preparación no se puede verificar (está «{$etapa}»).");
        }
        $errores = [];
        foreach ($manifiesto['plan'] as $nombreCarpeta => $contenido) {
            $copia = $carpeta.'/preparacion/'.$nombreCarpeta;
            $presentes = is_dir($copia) ? self::archivos($copia) : [];
            foreach ($contenido['archivos'] as $relativa => $archivo) {
                $ruta = $presentes[$relativa] ?? null;
                if ($ruta === null) {
                    $errores['falta_en_la_copia'] = ($errores['falta_en_la_copia'] ?? 0) + 1;
                } elseif (filesize($ruta) !== $archivo['bytes'] || ! hash_equals($archivo['sha256'], hash_file('sha256', $ruta))) {
                    $errores['copia_distinta'] = ($errores['copia_distinta'] ?? 0) + 1;
                }
            }
            $sobrantes = count(array_diff_key($presentes, $contenido['archivos']));
            if ($sobrantes > 0) {
                $errores['archivos_de_mas'] = $sobrantes;
            }
        }
        if (InventarioArchivos::de($institucion)['huella'] !== $manifiesto['huella_inventario']) {
            $errores['inventario_cambio'] = 1;
        }
        if ($errores !== []) {
            self::estado($carpeta, 'verificacion_fallida');
            throw new NormalizacionRechazada('La verificación falló ('.self::codigos($errores).'): no se cambió nada.');
        }
        self::estado($carpeta, 'verificada');

        return $manifiesto;
    }

    /**
     * Etapa 3. Si se interrumpe o la comprobación final falla, revierte.
     *
     * @param  Closure(string $carpeta): void|null  $avance  tras poner cada carpeta en su lugar
     * @return array<string, mixed> el manifiesto
     */
    public static function cambiar(Institucion $institucion, string $preparacion, ?Closure $avance = null): array
    {
        self::exigir($institucion);
        [$carpeta] = self::abrir($institucion, $preparacion);
        if ((self::leerEstado($carpeta)['etapa'] ?? null) !== 'verificada') {
            throw new NormalizacionRechazada('Verifica la preparación antes de cambiar.');
        }
        $manifiesto = self::verificar($institucion, $preparacion);
        $propia = storage_path('app/private/'.$institucion->almacenamiento);
        self::estado($carpeta, 'cambiando');
        try {
            foreach (array_keys($manifiesto['plan']) as $nombreCarpeta) {
                $actual = $propia.'/'.$nombreCarpeta;
                if ((file_exists($actual) || is_link($actual)) && ! @rename($actual, $carpeta.'/anteriores/'.$nombreCarpeta)) {
                    throw new NormalizacionRechazada('No se pudo apartar una carpeta de la institución.');
                }
                if (! @rename($carpeta.'/preparacion/'.$nombreCarpeta, $actual)) {
                    throw new NormalizacionRechazada('No se pudo poner en su lugar una carpeta preparada.');
                }
                if ($avance) {
                    $avance($nombreCarpeta);
                }
            }
            self::comprobarFinal($institucion, $manifiesto);
        } catch (Throwable $error) {
            self::deshacer($institucion, $carpeta, $manifiesto, false);
            self::estado($carpeta, 'cambio_revertido');
            throw new NormalizacionRechazada('El cambio no se completó y se revirtió: la institución quedó como estaba.', 0, $error);
        }
        self::estado($carpeta, 'cambiada');

        return $manifiesto;
    }

    /**
     * Etapa 4: deshace un cambio (completo o interrumpido).
     *
     * @return array<string, mixed> el manifiesto
     */
    public static function revertir(Institucion $institucion, string $preparacion): array
    {
        self::exigir($institucion);
        [$carpeta, $manifiesto] = self::abrir($institucion, $preparacion);
        self::deshacer($institucion, $carpeta, $manifiesto, true);
        self::estado($carpeta, 'revertida');

        return $manifiesto;
    }

    /** La última preparación de la institución, o null. */
    public static function ultima(Institucion $institucion): ?string
    {
        $nombres = array_filter(@scandir(self::raiz()) ?: [], fn (string $n): bool => str_starts_with($n, $institucion->slug.'-')
            && preg_match(self::PATRON_PREPARACION, $n) && is_dir(self::raiz().'/'.$n));
        sort($nombres);

        return $nombres === [] ? null : end($nombres);
    }

    /** @return array<string, mixed> */
    public static function leerEstado(string $carpeta): array
    {
        $contenido = @file_get_contents($carpeta.'/estado.json');

        return $contenido === false ? [] : (array) json_decode($contenido, true);
    }

    private static function exigir(Institucion $institucion): void
    {
        if ($motivo = self::motivoNoNormalizable($institucion)) {
            throw new NormalizacionRechazada($motivo);
        }
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private static function abrir(Institucion $institucion, string $preparacion): array
    {
        if (! preg_match(self::PATRON_PREPARACION, $preparacion) || ! str_starts_with($preparacion, $institucion->slug.'-')) {
            throw new NormalizacionRechazada('Esa preparación no es de esta institución.');
        }
        $carpeta = self::raiz().'/'.$preparacion;
        $manifiesto = json_decode((string) @file_get_contents($carpeta.'/manifiesto.json'), true);
        if (! is_array($manifiesto) || ($manifiesto['institucion_id'] ?? null) !== $institucion->id || ($manifiesto['almacenamiento'] ?? null) !== $institucion->almacenamiento) {
            throw new NormalizacionRechazada('La preparación no existe o no corresponde a esta institución.');
        }

        return [$carpeta, $manifiesto];
    }

    /** Tras el cambio: todo se lee de su carpeta real, sin enlaces, con las mismas huellas que antes. */
    private static function comprobarFinal(Institucion $institucion, array $manifiesto): void
    {
        $inventario = InventarioArchivos::de($institucion);
        $bien = $inventario['apto'] && collect($inventario['enlaces'])->where('tipo', 'comun')->isEmpty();
        foreach ($inventario['referencias'] as $r) {
            $bien = $bien && $r['estado'] === 'propio' && ($manifiesto['referencias'][self::claveReferencia($r)] ?? null) === $r['sha256_actual'];
        }
        if (! $bien || count($inventario['referencias']) !== count($manifiesto['referencias'])) {
            throw new NormalizacionRechazada('La comprobación final no coincide con el inventario previo.');
        }
    }

    /** Devuelve cada carpeta a su lugar según lo que hay en el disco, en orden inverso. */
    private static function deshacer(Institucion $institucion, string $carpeta, array $manifiesto, bool $comprobarNuevos): void
    {
        $propia = storage_path('app/private/'.$institucion->almacenamiento);
        foreach (array_reverse(array_keys($manifiesto['plan'])) as $nombreCarpeta) {
            $actual = $propia.'/'.$nombreCarpeta;
            $preparada = $carpeta.'/preparacion/'.$nombreCarpeta;
            $anterior = $carpeta.'/anteriores/'.$nombreCarpeta;
            $existe = fn (string $r): bool => file_exists($r) || is_link($r);
            // La carpeta preparada está puesta en su lugar: ni enlace, ni copia pendiente en preparacion/.
            $puesta = $existe($actual) && ! is_link($actual) && ! $existe($preparada)
                && ($existe($anterior) || $manifiesto['plan'][$nombreCarpeta]['actual'] === 'no_existe');
            if ($puesta) {
                $nuevos = count(array_diff_key(self::archivos($actual), $manifiesto['plan'][$nombreCarpeta]['archivos']));
                if ($comprobarNuevos && $nuevos > 0) {
                    throw new NormalizacionRechazada("La carpeta «{$nombreCarpeta}» tiene {$nuevos} archivo(s) nuevo(s) desde el cambio: no se revierte sin revisarlos.");
                }
                if (! @rename($actual, $preparada)) {
                    throw new NormalizacionRechazada('No se pudo retirar una carpeta normalizada: revisa a mano antes de seguir.');
                }
            }
            if (! $existe($actual) && $existe($anterior) && ! @rename($anterior, $actual)) {
                throw new NormalizacionRechazada('No se pudo devolver una carpeta a su lugar: revisa a mano antes de seguir.');
            }
        }
    }

    /** Copia un archivo sin sobrescribir y comprueba tamaño y huella. */
    private static function copiar(string $origen, string $destino, array $esperado): void
    {
        if (! is_dir(dirname($destino)) && ! @mkdir(dirname($destino), 0700, true)) {
            throw new NormalizacionRechazada('No se pudo crear una carpeta de la copia.');
        }
        $entrada = @fopen($origen, 'rb');
        $salida = $entrada === false ? false : @fopen($destino, 'xb');
        try {
            if ($entrada === false || $salida === false || stream_copy_to_stream($entrada, $salida) === false) {
                throw new NormalizacionRechazada('No se pudo copiar un archivo (falta el original o la copia ya existía).');
            }
        } finally {
            is_resource($entrada) && fclose($entrada);
            is_resource($salida) && fclose($salida);
        }
        @chmod($destino, fileperms($origen) & 0777);
        if (filesize($destino) !== $esperado['bytes'] || ! hash_equals($esperado['sha256'], hash_file('sha256', $destino))) {
            throw new NormalizacionRechazada('Un archivo copiado no coincide con el inventario.');
        }
    }

    /** @return array<string, string> ruta relativa => ruta completa, sin seguir enlaces */
    private static function archivos(string $carpeta): array
    {
        $archivos = [];
        $recorrido = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($carpeta, FilesystemIterator::SKIP_DOTS));
        foreach ($recorrido as $entrada) {
            if ($entrada->isFile() && ! $entrada->isLink()) {
                $archivos[substr($entrada->getPathname(), strlen($carpeta) + 1)] = $entrada->getPathname();
            }
        }
        ksort($archivos);

        return $archivos;
    }

    private static function claveReferencia(array $r): string
    {
        return $r['fuente']."\0".$r['disco']."\0".$r['ruta'];
    }

    /** @param array<string, int> $conteos */
    private static function codigos(array $conteos): string
    {
        return collect($conteos)->map(fn (int $n, string $codigo): string => "{$codigo}: {$n}")->implode(', ');
    }

    private static function estado(string $carpeta, string $etapa): void
    {
        self::escribir($carpeta.'/estado.json', ['etapa' => $etapa, 'actualizado_en' => now()->toIso8601String()]);
    }

    /** Escritura atómica (temporal + rename), 0600. */
    private static function escribir(string $ruta, array $datos): void
    {
        $temporal = $ruta.'.tmp';
        file_put_contents($temporal, json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        chmod($temporal, 0600);
        rename($temporal, $ruta);
    }
}
