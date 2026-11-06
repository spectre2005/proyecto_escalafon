<?php

return [
    'url' => env('OLLAMA_URL', 'http://ollama:11434'),
    'modelo' => env('OLLAMA_MODEL', 'qwen3:1.7b'),
    // Menos que los 280 s que espera el navegador y los 300 s de nginx.
    'timeout' => (int) env('OLLAMA_TIMEOUT', 150),
    'hilos' => (int) env('OLLAMA_HILOS', 2),
];
