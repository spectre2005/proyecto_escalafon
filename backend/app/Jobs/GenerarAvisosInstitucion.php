<?php

namespace App\Jobs;

use App\Support\GeneradorAvisos;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerarAvisosInstitucion implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(): void
    {
        GeneradorAvisos::generar();
    }
}
