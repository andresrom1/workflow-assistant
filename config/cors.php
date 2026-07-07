<?php

return [
    'paths' => [
        'api/*',
        // 'tools/*',
    ],
    'allowed_methods' => ['*'],
    'allowed_origins' => [
        'http://localhost:5173',
        'https://broker-ai-web.onrender.com',
        'https://openai-chatkit-starter-app-olive-eta.vercel.app', // Tu chatkit
        'http://localhost:3000', // Next.js local
        'http://127.0.0.1:3000', // Next.js local
    ],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true, // Sin cookies → false | Con cookies → true + origins explícitos
];
