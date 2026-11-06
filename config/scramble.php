<?php

use Dedoc\Scramble\SecurityDocumentation\MiddlewareAuthSecurityStrategy;

return [
    'info' => [
        'version' => env('API_VERSION', '0.0.1'),
        'description' => 'Use POST /v1/auth/login to obtain the Bearer token in data.token for protected endpoints.',
    ],

    'security_strategy' => MiddlewareAuthSecurityStrategy::class,
];
