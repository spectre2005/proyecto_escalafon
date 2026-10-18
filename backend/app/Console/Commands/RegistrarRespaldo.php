<?php

namespace App\Console\Commands;

use App\Models\Plataforma\Institucion;
use App\Models\Plataforma\Respaldo;
use App\Support\Plataforma\AuditoriaOperaciones;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Registra en la base de la plataforma el resultado de un respaldo (respaldar.sh) o de su verificación (restaurar.sh
 * verificar), para que el panel lo muestre. Repetirlo para el mismo objetivo y carpeta actualiza el registro.
 * Los fallos quedan además en la auditoría de la plataforma.
 */
class RegistrarRespaldo extends Command
{
    protected $signature = 'plataforma:registrar-respaldo
        {objetivo : Slug de la institución, plataforma o principal}
        {carpeta : Carpeta de la ejecución (AAAAMMDD-HHMMSS)}
        {--estado= : correcto o fallido (respaldo)}
        {--base= : Base respaldada}
        {--base-bytes= : Tamaño del volcado}
        {--base-sha256= : SHA-256 del volcado}
        {--archivos-bytes= : Tamaño del paquete de archivos}
        {--archivos-sha256= : SHA-256 del paquete de archivos}
        {--copia-externa=no_configurada : no_configurada, correcta o fallida}
        {--verificacion= : correcta o fallida (restauración de prueba de un respaldo ya registrado)}
        {--detalle= : Motivo de un fallo}';

    protected $description = 'Registra el resultado de un respaldo o de su verificación (lo usan respaldar.sh y restaurar.sh)';

    public function handle(): int
    {
        $datos = [...$this->arguments(), ...$this->options()];
        $verificacion = $this->option('verificacion') !== null;
        $validacion = Validator::make($datos, [
            'objetivo' => ['required', 'string', 'max:60', 'regex:'.Institucion::PATRON_SLUG],
            'carpeta' => ['required', 'regex:'.Respaldo::PATRON_CARPETA],
            'estado' => [$verificacion ? 'nullable' : 'required', 'in:'.implode(',', Respaldo::ESTADOS)],
            'verificacion' => ['nullable', 'in:'.implode(',', Respaldo::VERIFICACIONES)],
            'base' => ['nullable', 'regex:'.Institucion::PATRON_BASE_DATOS],
            'base-bytes' => ['nullable', 'integer', 'min:0'], 'archivos-bytes' => ['nullable', 'integer', 'min:0'],
            'base-sha256' => ['nullable', 'regex:/^[0-9a-f]{64}$/'], 'archivos-sha256' => ['nullable', 'regex:/^[0-9a-f]{64}$/'],
            'copia-externa' => ['required', 'in:'.implode(',', Respaldo::COPIAS_EXTERNAS)],
            'detalle' => ['nullable', 'string', 'max:500'],
        ]);
        if ($validacion->fails()) {
            foreach ($validacion->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $institucion = Institucion::query()->where('slug', $datos['objetivo'])->first();
        if (! $institucion && ! in_array($datos['objetivo'], ['plataforma', 'principal'], true)) {
            $this->error("No existe una institución «{$datos['objetivo']}».");

            return self::FAILURE;
        }
        $clave = ['objetivo' => $datos['objetivo'], 'carpeta' => $datos['carpeta']];

        if ($verificacion) {
            $respaldo = Respaldo::query()->where($clave)->first();
            if (! $respaldo) {
                $this->error('No hay un respaldo registrado con ese objetivo y esa carpeta.');

                return self::FAILURE;
            }
            $respaldo->update(['verificacion' => $datos['verificacion'], 'verificado_en' => now(), 'detalle_verificacion' => $datos['detalle']]);
            if ($datos['verificacion'] === 'fallida') {
                AuditoriaOperaciones::registrar('respaldo.verificacion_fallida', null, null, $institucion?->id, null, $clave, $datos['detalle']);
            }
            $this->info("Verificación de {$datos['objetivo']} ({$datos['carpeta']}): {$datos['verificacion']}.");

            return self::SUCCESS;
        }

        Respaldo::query()->updateOrCreate($clave, [
            'institucion_id' => $institucion?->id, 'estado' => $datos['estado'], 'base_datos' => $datos['base'],
            'base_bytes' => $datos['base-bytes'], 'base_sha256' => $datos['base-sha256'],
            'archivos_bytes' => $datos['archivos-bytes'], 'archivos_sha256' => $datos['archivos-sha256'],
            'copia_externa' => $datos['copia-externa'], 'detalle' => $datos['detalle'],
        ]);
        if ($datos['estado'] === Respaldo::FALLIDO || $datos['copia-externa'] === 'fallida') {
            AuditoriaOperaciones::registrar('respaldo.fallido', null, null, $institucion?->id, null,
                [...$clave, 'estado' => $datos['estado'], 'copia_externa' => $datos['copia-externa']], $datos['detalle']);
        }
        $this->info("Respaldo de {$datos['objetivo']} ({$datos['carpeta']}): {$datos['estado']}, copia externa {$datos['copia-externa']}.");

        return self::SUCCESS;
    }
}
