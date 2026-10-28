<?php

return [
    'url' => env('OLLAMA_INGRESO_URL', 'http://ollama:11434'),
    'modelo' => env('OLLAMA_INGRESO_MODELO', 'qwen3:1.7b'),
    'timeout' => (int) env('OLLAMA_INGRESO_TIMEOUT', 180),
];
