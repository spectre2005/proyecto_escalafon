<?php

return [
    // Solo habilitar después de configurar y probar el transporte de correo.
    'correo_habilitado' => (bool) env('AVISOS_CORREO_HABILITADO', false),
];
