<?php

return [
    'url' => env('OLLAMA_URL', 'http://ollama:11434'),
    'modelo' => env('OLLAMA_MODEL', 'qwen3:1.7b'),
    'timeout' => 150,
];
