<?php

return [
    'default' => env('CACHE_DRIVER', 'file'),

    'stores' => [
        'array' => [
            'driver' => 'array',
        ],
        'file' => [
            'driver' => 'file',
            'path' => storage_path('framework/cache'),
        ],
    ],

    'prefix' => env('CACHE_PREFIX', 'afa_crm_cache'),
];
