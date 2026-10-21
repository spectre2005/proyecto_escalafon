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
     * Solo con modo unica: carpeta propia de la institución de esta base (instituciones/{slug}) para los discos privados
     * (App\Support\Plataforma\AlmacenamientoUnica). Vacía (por defecto): las carpetas comunes, como siempre. Se
     * establece solo después de normalizar esa carpeta (plataforma:normalizar-almacenamiento); con un valor que no
     * corresponde a esta base, la aplicación no arranca. Con subdominio no se usa.
     */
    'almacenamiento_unica' => env('PLATAFORMA_ALMACENAMIENTO_UNICA', ''),

    /*
     * Tiempo de uso pagado (periodos_servicio). Las fechas se comparan con «hoy» en esta zona horaria (la aplicación
     * corre en UTC). Los administradores de la institución ven un aviso desde estos días antes del vencimiento.
     */
    'zona_horaria' => env('PLATAFORMA_ZONA_HORARIA', 'America/Lima'),
    'aviso_vencimiento_dias' => (int) env('PLATAFORMA_AVISO_VENCIMIENTO_DIAS', 15),

    /* Identidad de la institución local de demostración; no contiene cuentas de acceso. */
    'institucion_prueba' => [
        'slug' => env('PLATAFORMA_PRUEBA_SLUG'),
        'nombre' => env('PLATAFORMA_PRUEBA_NOMBRE'),
    ],

    /*
     * Sesión del panel de plataforma: cookie propia (nombre y path distintos de la institucional), para que iniciar o
     * cerrar sesión ahí no toque la sesión institucional del mismo navegador. La aplica App\Http\Middleware\SesionPlataforma.
     */
    'sesion' => [
        'cookie' => env('PLATAFORMA_SESSION_COOKIE', Str::slug((string) env('APP_NAME', 'laravel')).'-plataforma-session'),
        'path' => '/api/plataforma',
    ],
];
