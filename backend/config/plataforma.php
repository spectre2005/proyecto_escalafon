<?php

use Illuminate\Support\Str;

return [
    /*
     * Sesión del panel de plataforma: cookie propia (nombre y path distintos de la institucional), para que iniciar o
     * cerrar sesión ahí no toque la sesión institucional del mismo navegador. La aplica App\Http\Middleware\SesionPlataforma.
     */
    'sesion' => [
        'cookie' => env('PLATAFORMA_SESSION_COOKIE', Str::slug((string) env('APP_NAME', 'laravel')).'-plataforma-session'),
        'path' => '/api/plataforma',
    ],
];
