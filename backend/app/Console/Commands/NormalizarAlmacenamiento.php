<?php

namespace App\Console\Commands;

use App\Models\Plataforma\Institucion;
use App\Support\Plataforma\AlmacenamientoUnica;
use App\Support\Plataforma\AuditoriaOperaciones;
use App\Support\Plataforma\InventarioArchivos;
use App\Support\Plataforma\NormalizacionAlmacenamiento;
use App\Support\Plataforma\NormalizacionRechazada;
use Illuminate\Console\Command;
use Throwable;

/**
 * Inventario y normalización del almacenamiento de una institución (NormalizacionAlmacenamiento). Sin opciones, solo
 * inventaría y simula: no escribe nada. Las etapas que tocan archivos (--preparar, --verificar, --cambiar,
 * --revertir) van de una en una, exigen modo subdominio y la institución suspendida, y piden escribir su slug.
 *
 * Al terminar --cambiar indica la línea PLATAFORMA_ALMACENAMIENTO_UNICA para los equipos en modo unica con esta base
 * (solo si la carpeta ya es real y sin enlaces); al terminar --revertir, que hay que quitarla.
 *
 * La salida y la auditoría solo llevan cifras, códigos, nombres de carpeta de primer nivel y huellas agregadas: nunca
 * rutas de archivos (las de las fotos llevan el DNI) ni nombres de documentos.
 */
class NormalizarAlmacenamiento extends Command
{
    protected $signature = 'plataforma:normalizar-almacenamiento
        {slug : Identificador de la institución}
        {--preparar : Copia de preparación, sin tocar los originales}
        {--verificar : Comprueba la copia de preparación}
        {--cambiar : Pone las carpetas preparadas en lugar de los enlaces}
        {--revertir : Deshace un cambio, completo o interrumpido}
        {--preparacion= : Preparación a usar (por defecto, la última de la institución)}
        {--confirmar= : El slug de la institución, para no preguntarlo}';

    protected $description = 'Inventaría (por defecto, sin cambiar nada) o normaliza el almacenamiento de una institución';

    private const ETAPAS = ['preparar', 'verificar', 'cambiar', 'revertir'];

    public function handle(): int
    {
        $institucion = Institucion::query()->where('slug', (string) $this->argument('slug'))->first();
        if (! $institucion) {
            $this->error('No existe esa institución.');

            return self::FAILURE;
        }
        $etapas = array_values(array_filter(self::ETAPAS, fn (string $etapa): bool => (bool) $this->option($etapa)));
        if (count($etapas) > 1) {
            $this->error('Indica una sola etapa.');

            return self::FAILURE;
        }

        return $etapas === [] ? $this->inventario($institucion) : $this->etapa($institucion, $etapas[0]);
    }

    private function inventario(Institucion $institucion): int
    {
        try {
            $inventario = InventarioArchivos::de($institucion);
        } catch (Throwable) {
            $this->error('No se pudo hacer el inventario (la base o la carpeta de la institución no se pudieron leer).');

            return self::FAILURE;
        }
        $resumen = InventarioArchivos::resumen($inventario);
        $this->info("Inventario de {$institucion->slug} (simulación: no se copió ni se cambió nada).");
        $filas = [['Referencias', $resumen['referencias']], ['Espacio referenciado', number_format($resumen['bytes'], 0, ',', '.').' bytes']];
        foreach (['por_fuente' => 'Fuente', 'por_estado' => 'Estado', 'marcas' => 'Marca', 'enlaces' => 'Enlaces', 'huerfanos' => 'Huérfanos'] as $clave => $titulo) {
            foreach ($resumen[$clave] as $codigo => $cantidad) {
                $filas[] = ["{$titulo}: {$codigo}", $cantidad];
            }
        }
        $this->table(['Concepto', 'Cantidad'], $filas);
        $plan = NormalizacionAlmacenamiento::plan($inventario);
        foreach ($plan as $carpeta => $contenido) {
            $this->line(sprintf('  Normalizaría «%s» (hoy: %s): %d archivo(s), %s bytes.', $carpeta, $contenido['actual'], count($contenido['archivos']),
                number_format(collect($contenido['archivos'])->sum('bytes'), 0, ',', '.')));
        }
        if ($plan === []) {
            $this->line('  No hay carpetas que normalizar.');
        }
        $this->line('  Huella del inventario: '.$resumen['huella']);
        if (! $resumen['apto']) {
            $this->warn('Bloqueos: '.collect($resumen['bloqueos'])->map(fn (int $n, string $c): string => "{$c} ({$n})")->implode(', ').'. No se puede normalizar.');

            return self::FAILURE;
        }
        $this->info('Sin bloqueos.');

        return self::SUCCESS;
    }

    private function etapa(Institucion $institucion, string $etapa): int
    {
        if ($motivo = NormalizacionAlmacenamiento::motivoNoNormalizable($institucion)) {
            $this->error($motivo);

            return self::FAILURE;
        }
        $preparacion = $etapa === 'preparar' ? null : ($this->option('preparacion') ?: NormalizacionAlmacenamiento::ultima($institucion));
        if ($etapa !== 'preparar' && $preparacion === null) {
            $this->error('La institución no tiene ninguna preparación.');

            return self::FAILURE;
        }
        $confirmacion = $this->option('confirmar') ?? $this->ask("Etapa «{$etapa}» para «{$institucion->slug}». Escribe «{$institucion->slug}» para continuar");
        if ($confirmacion !== $institucion->slug) {
            $this->error('No coincide: no se hizo nada.');

            return self::FAILURE;
        }
        try {
            $manifiesto = match ($etapa) {
                'preparar' => null,
                'verificar' => NormalizacionAlmacenamiento::verificar($institucion, $preparacion),
                'cambiar' => NormalizacionAlmacenamiento::cambiar($institucion, $preparacion),
                'revertir' => NormalizacionAlmacenamiento::revertir($institucion, $preparacion),
            };
            if ($etapa === 'preparar') {
                $preparacion = NormalizacionAlmacenamiento::preparar($institucion);
                $manifiesto = json_decode((string) file_get_contents(NormalizacionAlmacenamiento::raiz().'/'.$preparacion.'/manifiesto.json'), true);
            }
        } catch (Throwable $error) {
            $mensaje = $error instanceof NormalizacionRechazada ? $error->getMessage() : 'Error inesperado ('.class_basename($error).'): no se continuó.';
            AuditoriaOperaciones::registrar('almacenamiento.normalizacion_fallida', null, null, $institucion->id, null,
                ['etapa' => $etapa, 'preparacion' => $preparacion], mb_substr($mensaje, 0, 500));
            $this->error($mensaje);

            return self::FAILURE;
        }
        $datos = ['etapa' => $etapa, 'preparacion' => $preparacion, 'carpetas' => array_keys($manifiesto['plan']),
            'archivos' => $manifiesto['archivos'], 'bytes' => $manifiesto['bytes'], 'huella_inventario' => $manifiesto['huella_inventario']];
        AuditoriaOperaciones::registrar("almacenamiento.normalizacion_{$etapa}", null, null, $institucion->id, null, $datos);
        $this->info(sprintf('Etapa «%s» terminada (%s): %d carpeta(s), %d archivo(s), %s bytes.', $etapa, $preparacion,
            count($datos['carpetas']), $datos['archivos'], number_format($datos['bytes'], 0, ',', '.')));
        if ($etapa === 'cambiar') {
            $this->variableUnica($institucion);
        } elseif ($etapa === 'revertir') {
            $this->warn("Si algún equipo en modo unica tiene PLATAFORMA_ALMACENAMIENTO_UNICA={$institucion->almacenamiento}, quítala de backend/.env: "
                .'la carpeta vuelve a tener enlaces y la aplicación no arrancaría con ella.');
        }

        return self::SUCCESS;
    }

    /** La línea de backend/.env para el modo unica (sin secretos: solo la carpeta), si la carpeta ya sirve. */
    private function variableUnica(Institucion $institucion): void
    {
        if ($motivo = AlmacenamientoUnica::motivoCarpeta($institucion->almacenamiento)) {
            $this->warn("La carpeta todavía no sirve para el modo unica ({$motivo}): no añadas PLATAFORMA_ALMACENAMIENTO_UNICA.");

            return;
        }
        $this->line('Modo unica: en el equipo cuya base (DB_DATABASE) es la de esta institución, y solo si usa PLATAFORMA_MODO=unica, añade a backend/.env:');
        $this->line("  PLATAFORMA_ALMACENAMIENTO_UNICA={$institucion->almacenamiento}");
        $this->line('No la añadas antes de terminar y comprobar la normalización: las carpetas deben ser reales, sin enlaces (si no, la aplicación no arranca). '
            .'Con subdominio no hace falta. Después, compruébalo con php artisan produccion:verificar.');
    }
}
