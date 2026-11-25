<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('legajo:recibir-respuestas')->everyMinute()->withoutOverlapping(10);

// Archivos que quedaron sin revisar (cargados antes del antivirus) y, los domingos, todos con las firmas del día.
Schedule::command('antivirus:escanear')->dailyAt('02:30')->withoutOverlapping(120);
Schedule::command('antivirus:escanear --todos')->weeklyOn(0, '03:30')->withoutOverlapping(240);
