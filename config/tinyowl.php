<?php

declare(strict_types=1);

return [
    'api_key' => env('TINYOWL_API_KEY'),
    'project_secret' => env('TINYOWL_PROJECT_SECRET'),
    'service' => env('TINYOWL_SERVICE'),
    'environment' => env('TINYOWL_ENVIRONMENT', env('APP_ENV')),
];
