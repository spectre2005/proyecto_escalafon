<?php

return [
    'apisted' => [
        'token' => env('APISTED_TOKEN'),
        'institucion' => env('APISTED_INSTITUCION'),
        'timeout' => 20,
    ],
    // Validador desplegado por TI en la red de la entidad. Las credenciales nunca se entregan al navegador.
    'firma_peru' => [
        'url' => env('FIRMA_PERU_URL'),
        'credential' => env('FIRMA_PERU_CREDENTIAL'),
        'institucion' => env('FIRMA_PERU_INSTITUCION'),
        'timeout' => 20,
    ],
];
