<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class EnviarCorreosRequerimientos extends Command
{
    protected $signature = 'legajo:enviar-requerimientos';

    protected $description = 'Informa que los requerimientos se envían manualmente desde el panel';

    public function handle(): int
    {
        $this->info('El envío automático está desactivado. Registra y envía los requerimientos desde el panel.');

        return self::SUCCESS;
    }
}
