<?php

return [
    'pdfinfo_binario' => env('DOCUMENTOS_PDFINFO_BINARIO', 'pdfinfo'),
    'antivirus_host' => env('DOCUMENTOS_ANTIVIRUS_HOST', 'clamav'),
    'antivirus_puerto' => (int) env('DOCUMENTOS_ANTIVIRUS_PUERTO', 3310),
    'antivirus_timeout' => (int) env('DOCUMENTOS_ANTIVIRUS_TIMEOUT', 15),
    'clave' => env('DOCUMENTOS_CIFRADO_KEY'),
    'archivo_clave' => env('DOCUMENTOS_CIFRADO_CLAVE_ARCHIVO', base_path('storage/app/keys/documentos.key')),
    'claves_anteriores' => array_filter(explode(',', (string) env('DOCUMENTOS_CIFRADO_CLAVES_ANTERIORES', ''))),
];
