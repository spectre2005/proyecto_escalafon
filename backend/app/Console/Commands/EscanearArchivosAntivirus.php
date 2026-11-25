<?php

namespace App\Console\Commands;

use App\Models\ArchivoDocumento;
use App\Models\Plataforma\Institucion;
use App\Support\Antivirus\AntivirusNoDisponible;
use App\Support\Antivirus\EscanerAntivirus;
use App\Support\Plataforma\ConexionInstitucion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Revisa con el antivirus los PDF del legajo ya guardados que siguen «pendiente» (los cargados antes de tener antivirus
 * o con ANTIVIRUS_DRIVER=ninguno). Con --todos revisa también los ya limpios, útil tras actualizar las firmas. Solo
 * cambia archivos_documento.estado_antivirus: el documento y su PDF no se tocan. Un archivo infectado deja de poder
 * verse y descargarse (las vistas ya lo bloquean por ese estado).
 */
class EscanearArchivosAntivirus extends Command
{
    protected $signature = 'antivirus:escanear {--todos : Revisa también los archivos ya marcados como limpios}';

    protected $description = 'Revisa con el antivirus los PDF del legajo que aún no se revisaron';

    public function handle(EscanerAntivirus $motor): int
    {
        if (! $motor->activo()) {
            $this->error('No hay antivirus configurado (ANTIVIRUS_DRIVER=ninguno): no se revisó nada.');

            return self::FAILURE;
        }
        if (config('plataforma.modo') !== 'subdominio' || app()->bound(Institucion::class)) {
            return $this->revisar($motor, null);
        }
        $resultado = self::SUCCESS;
        foreach (Institucion::query()->get() as $institucion) {
            if (! $institucion->operativa() || ! $institucion->aprovisionada()) {
                continue;
            }
            try {
                if (ConexionInstitucion::usar($institucion, fn (): int => $this->revisar($motor, $institucion->slug)) !== self::SUCCESS) {
                    $resultado = self::FAILURE;
                }
            } catch (Throwable) {
                $this->error("{$institucion->slug}: no se pudo completar la revisión.");
                $resultado = self::FAILURE;
            }
        }

        return $resultado;
    }

    private function revisar(EscanerAntivirus $motor, ?string $institucion): int
    {
        $prefijo = $institucion === null ? '' : "{$institucion}: ";
        $estados = $this->option('todos') ? ['pendiente', 'limpio'] : ['pendiente'];
        $limpios = $infectados = $faltantes = 0;
        try {
            ArchivoDocumento::query()->whereIn('estado_antivirus', $estados)->orderBy('id')
                ->chunkById(100, function ($archivos) use ($motor, &$limpios, &$infectados, &$faltantes): void {
                    foreach ($archivos as $archivo) {
                        $disco = Storage::disk((string) $archivo->almacenamiento);
                        if (! $archivo->storage_path || ! $disco->exists($archivo->storage_path)) {
                            $faltantes++;

                            continue;
                        }
                        $amenaza = $motor->escanearContenido((string) $disco->get($archivo->storage_path));
                        if ($amenaza !== null) {
                            Log::warning('Antivirus: archivo guardado marcado como infectado.', ['archivo_id' => $archivo->id, 'amenaza' => $amenaza]);
                            $infectados++;
                        } else {
                            $limpios++;
                        }
                        $archivo->update(['estado_antivirus' => $amenaza === null ? 'limpio' : 'infectado']);
                    }
                });
        } catch (AntivirusNoDisponible $exception) {
            $this->error($prefijo.'el antivirus no respondió ('.$exception->getMessage().'). Lo revisado hasta ahora quedó guardado.');

            return self::FAILURE;
        }
        $this->info("{$prefijo}{$limpios} limpio(s), {$infectados} infectado(s), {$faltantes} sin archivo en disco.");
        if ($infectados > 0) {
            $this->warn($prefijo.'Los archivos infectados ya no se pueden ver ni descargar. Revisa el log para saber cuáles son.');
        }

        return self::SUCCESS;
    }
}
