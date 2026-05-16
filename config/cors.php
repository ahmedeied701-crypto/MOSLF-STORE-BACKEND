<?php

return [

    // 'paths' => ['api/*', 'cart/*', 'storage/*', 'products/*', 'customize/*', 'sanctum/csrf-cookie'],
    'paths' => ['*'],

    'allowed_methods' => ['*'],

    // 'allowed_origins' => [ 'https://moslf.de',
    // 'https://www.moslf.de',
    // 'http://localhost:5173',
    // 'http://127.0.0.1:5173',
    // ],
    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
