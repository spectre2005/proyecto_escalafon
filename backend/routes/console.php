<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('legajos:actualizar --todas')->dailyAt('00:05')->timezone('America/Lima')->withoutOverlapping();

Schedule::command('avisos:generar --todas --cola')->dailyAt('08:00')->timezone('America/Lima')->withoutOverlapping();
