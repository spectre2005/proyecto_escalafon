<?php

return [
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),
    // Activar exclusivamente trámites acordados con la institución. Renovación y consulta quedan disponibles.
    'rutas_requieren_vigencia' => ['api/v1/vencimientos/legajos/*/constancia'],
];
