<?php

use Illuminate\Support\Str;

return [
    /*
     * Cómo se identifica la institución de cada petición (App\Http\Middleware\ResolverInstitucion):
     * - unica (por defecto): una sola institución con la base y el almacenamiento del .env.
     * - subdominio: {slug}.{dominio} atiende con la base y el almacenamiento propios de esa institución (una base por
     *   cliente). El panel de plataforma solo responde en el dominio, sin subdominio.
     */
    'modo' => env('PLATAFORMA_MODO', 'unica'),
    'dominio' => env('PLATAFORMA_DOMINIO', 'localhost'),

    /*
     * Sesión del panel de plataforma: cookie propia (nombre y path distintos de la institucional), para que iniciar o
     * cerrar sesión ahí no toque la sesión institucional del mismo navegador. La aplica App\Http\Middleware\SesionPlataforma.
     */
    'sesion' => [
        'cookie' => env('PLATAFORMA_SESSION_COOKIE', Str::slug((string) env('APP_NAME', 'laravel')).'-plataforma-session'),
        'path' => '/api/plataforma',
    ],
];
