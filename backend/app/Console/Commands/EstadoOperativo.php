<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class EstadoOperativo extends Command
{
    protected $signature = 'sistema:estado {--produccion : Comprueba además requisitos de configuración productiva}';

    protected $description = 'Comprueba BD, almacenamiento y heartbeat sin revelar secretos ni enviar mensajes';

    public function handle(): int
    {
        $checks = [];
        try {
            DB::select('SELECT 1');
            $checks['database'] = true;
        } catch (Throwable) {
            $checks['database'] = false;
        }
        $ruta = 'health/'.Str::uuid();
        try {
            $disco = Storage::disk(config('legajo.disco_documentos'));
            $checks['storage'] = $disco->put($ruta, 'health') && $disco->get($ruta) === 'health';
        } catch (Throwable) {
            $checks['storage'] = false;
        } finally {
            try {
                Storage::disk(config('legajo.disco_documentos'))->delete($ruta);
            } catch (Throwable) {
                $checks['storage'] = false;
            }
        }
        try {
            $heartbeat = Cache::get('scheduler.heartbeat');
            $checks['scheduler'] = is_numeric($heartbeat) && now()->timestamp - (int) $heartbeat >= 0 && now()->timestamp - (int) $heartbeat <= 180;
        } catch (Throwable) {
            $checks['scheduler'] = false;
        }
        if ($this->option('produccion')) {
            $checks['debug_off'] = ! config('app.debug');
            $checks['secure_cookie'] = (bool) config('session.secure');
            $checks['permissions_database'] = in_array(config('permisos.fuente'), ['dual', 'database'], true);
            $checks['antivirus_required'] = config('antivirus.enabled') && config('antivirus.enforce');
            $checks['ses_starttls'] = config('mail.default') === 'ses_smtp' && config('mail.mailers.ses_smtp.require_tls');
            $checks['kms_separate_keys'] = filled(config('seguridad.kms_key_id')) && filled(config('seguridad.backup_kms_key_id')) && config('seguridad.kms_key_id') !== config('seguridad.backup_kms_key_id');
        }
        $this->line(json_encode(['checks' => $checks], JSON_THROW_ON_ERROR));

        return in_array(false, $checks, true) ? self::FAILURE : self::SUCCESS;
    }
}
