<?php

declare(strict_types=1);

return [
    'url' => env('ASSAY_URL'),
    'token' => env('ASSAY_TOKEN'),
    'app' => env('ASSAY_APP', env('APP_NAME', 'laravel')),
    'environment' => env('ASSAY_ENVIRONMENT', env('APP_ENV', 'production')),
    'capture' => env('ASSAY_CAPTURE', 'usage'),
    'deploy' => env('ASSAY_DEPLOY', env('NIGHTWATCH_DEPLOY')),
    'batch_size' => (int) env('ASSAY_BATCH_SIZE', 100),
    'max_batch_bytes' => (int) env('ASSAY_MAX_BATCH_BYTES', 4_194_304),
    'retry_for_seconds' => (int) env('ASSAY_RETRY_FOR_SECONDS', 86400),
    'retry_delay_seconds' => (int) env('ASSAY_RETRY_DELAY_SECONDS', 60),
    'queue' => env('ASSAY_QUEUE'),
    'connect_timeout' => (float) env('ASSAY_CONNECT_TIMEOUT', 0.5),
    'timeout' => (float) env('ASSAY_TIMEOUT', 5),
];
