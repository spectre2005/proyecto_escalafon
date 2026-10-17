<?php

return [
    'enabled' => (bool) env('ANTIVIRUS_ENABLED', false),
    'enforce' => (bool) env('ANTIVIRUS_ENFORCE', ! in_array(env('APP_ENV'), ['local', 'testing'], true)),
    'endpoint' => env('ANTIVIRUS_ENDPOINT', 'tcp://antivirus:3310'),
    'timeout_seconds' => 10,
    'max_bytes' => 20 * 1024 * 1024,
];
