<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('legajos:controlar-vencimientos')->dailyAt('00:00')->timezone('America/Lima')->withoutOverlapping();
Schedule::command('legajos:controlar-vencimientos')->hourly()->timezone('America/Lima')->withoutOverlapping();
Schedule::command('legajos:enviar-avisos')->everyMinute()->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
