<?php

namespace App\Console\Commands;

use App\Models\Plataforma\Institucion;
use Illuminate\Console\Command;
use Throwable;

/**
 * Qué respalda docker/produccion/respaldar.sh, una línea por objetivo, separada por tabuladores:
 *   objetivo  base_datos  archivos
 * - plataforma: su base (instituciones, superadministradores, pagos y auditoría); sin archivos («-»).
 * - cada institución aprovisionada (también suspendida): su base y su carpeta de storage/app/private.
 * - principal: la base del .env, solo si no es de ninguna institución (modo unica o anterior al panel); sus archivos
 *   son storage/app/private sin instituciones/ («.»).
 * Solo salen nombres que cumplen los patrones de Institucion: el script los usa en comandos de shell.
 */
class PlanRespaldo extends Command
{
    protected $signature = 'plataforma:respaldo-plan';

    protected $description = 'Lista las bases y carpetas a respaldar (lo usa docker/produccion/respaldar.sh)';

    public function handle(): int
    {
        $lineas = [];
        $plataforma = (array) config('database.connections.plataforma');
        if (($plataforma['driver'] ?? null) === 'pgsql') {
            $lineas[] = ['plataforma', (string) $plataforma['database'], '-'];
        }
        $vinculadas = [];
        try {
            $instituciones = Institucion::query()->whereNotNull('aprovisionada_en')->orderBy('slug')->get();
        } catch (Throwable $error) {
            $this->error('No se pudo leer la base de la plataforma: '.$error->getMessage());

            return self::FAILURE;
        }
        foreach ($instituciones as $institucion) {
            $vinculadas[] = $institucion->base_datos;
            $lineas[] = [$institucion->slug, $institucion->base_datos, $institucion->almacenamiento];
        }
        $principal = (string) config('database.connections.pgsql.database');
        if ($principal !== '' && ! in_array($principal, $vinculadas, true)) {
            $lineas[] = ['principal', $principal, '.'];
        }

        foreach ($lineas as [$objetivo, $base, $archivos]) {
            $valido = (in_array($objetivo, ['plataforma', 'principal'], true) || preg_match(Institucion::PATRON_SLUG, $objetivo))
                && preg_match(Institucion::PATRON_BASE_DATOS, $base)
                && (in_array($archivos, ['-', '.'], true) || preg_match(Institucion::PATRON_ALMACENAMIENTO, $archivos));
            if (! $valido) {
                $this->error("Se omite «{$objetivo}»: su base o su carpeta no tienen un nombre válido.");

                continue;
            }
            $this->line("{$objetivo}\t{$base}\t{$archivos}");
        }

        return self::SUCCESS;
    }
}
