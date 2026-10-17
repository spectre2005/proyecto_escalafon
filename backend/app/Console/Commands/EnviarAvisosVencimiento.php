<?php

namespace App\Console\Commands;

use App\Support\AvisosVencimiento;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class EnviarAvisosVencimiento extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'legajos:enviar-avisos';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Procesa la bandeja de correos de vencimiento con reintentos y trazabilidad';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $bloqueo = Cache::lock('legajos.enviar-avisos', 600);
        if (! $bloqueo->get()) {
            return self::SUCCESS;
        }
        try {
            $this->info(json_encode(AvisosVencimiento::procesar(), JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } finally {
            $bloqueo->release();
        }
    }
}
