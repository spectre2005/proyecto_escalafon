<?php

/*
| Antivirus de los archivos que entran al sistema (PDF del legajo, entregas de requerimientos, adjuntos de Gmail,
| PDF del escáner con IA y logo). Ver App\Support\Antivirus\Antivirus.
|
| - clamav: escanea con el servicio clamd (contenedor «clamav» de docker-compose). Si no responde, la subida se
|   rechaza: ningún archivo entra sin revisar.
| - ninguno: solo para desarrollo sin el contenedor. Los archivos quedan con estado_antivirus = pendiente. En
|   producción se ignora y se usa clamav (y produccion:verificar lo marca como error).
*/

return [
    'driver' => env('ANTIVIRUS_DRIVER', 'clamav'),

    'clamav' => [
        'host' => env('CLAMAV_HOST', 'clamav'),
        'puerto' => (int) env('CLAMAV_PUERTO', 3310),
        // Segundos para conectar y para esperar la respuesta de un archivo.
        'tiempo_conexion' => (int) env('CLAMAV_TIEMPO_CONEXION', 5),
        'tiempo_respuesta' => (int) env('CLAMAV_TIEMPO_RESPUESTA', 60),
    ],
];
