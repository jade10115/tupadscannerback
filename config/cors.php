<?php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie', '*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'https://tupadscanner.onrender.com',
        'http://localhost:5173',
        'http://localhost:3000',
        'https://admirable-belekoy-35b3fd.netlify.app/login',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];