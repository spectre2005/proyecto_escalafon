<?php

use App\Support\Identidad\ConsultaDniFactiliza;
use App\Support\Identidad\ConsultaDniSimulada;

/*
 * Consulta de identidad por DNI en el registro manual (App\Support\Identidad). El proveedor lo elige el servidor; el
 * frontend y el controlador no cambian al conectar otro. Que el módulo «consulta_dni» esté activo en una institución
 * (ModulosSistema) no significa que haya un proveedor: sin uno disponible, la consulta figura como no disponible y el
 * registro sigue manual. Detalle en docs/CONSULTA_IDENTIDAD.md.
 */
return [

    // ninguno (por defecto) o una clave de «proveedores». «simulado» solo responde en APP_ENV local o testing;
    // «factiliza» solo con FACTILIZA_TOKEN.
    'proveedor' => env('IDENTIDAD_PROVEEDOR', 'ninguno'),

    // Implementaciones de App\Support\Identidad\ConsultaDni. El proveedor oficial (RENIEC/PIDE) se agregará aquí cuando
    // exista un acceso autorizado y su documentación técnica verificada.
    'proveedores' => [
        'simulado' => ConsultaDniSimulada::class,
        'factiliza' => ConsultaDniFactiliza::class,
    ],

    // Factiliza (servicio externo de pago, no oficial). El token solo vive en el entorno del servidor: nunca en el
    // repositorio, el frontend ni los logs.
    'factiliza' => [
        'token' => env('FACTILIZA_TOKEN'),
    ],

    // Segundos de espera máximos para un proveedor real (el simulado no los usa).
    'tiempo_espera' => (int) env('IDENTIDAD_TIEMPO_ESPERA', 8),

    // Consultas por minuto: por usuario y por institución (la caché ya separa cada institución).
    'limite_por_usuario' => (int) env('IDENTIDAD_LIMITE_USUARIO', 10),
    'limite_por_institucion' => (int) env('IDENTIDAD_LIMITE_INSTITUCION', 60),

];
