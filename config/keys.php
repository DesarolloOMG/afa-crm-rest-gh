<?php

return [
    'dropbox' => env('DROPBOX_TOKEN', ''),
    'paqueterias' => env('PAQUETERIAS_TOKEN', ''),
    'exel_user' => env('EXEL_DEL_NORTE_USER', ''),
    'exel_password' => env('EXEL_DEL_NORTE_PASSWORD', ''),
    'exel_cliente' => env('EDEL_DEL_NORTE_CLIENTE', ''),
    'arroba_user' => env('ARROBA_USER', ''),
    'arroba_password' => env('ARROBA_PASSWORD', ''),

    'facturoporti_users' => [
        'sandbox' => [
            'user' => env('FACTUROPORTI_USER_SANDBOX', ''),
            'password' => env('FACTUROPORTI_PASSWORD_SANDBOX', ''),
        ],
        'production' => [
            'user' => env('FACTUROPORTI_USER_PRODUCTION', ''),
            'password' => env('FACTUROPORTI_USER_PRODUCTION', ''),
        ],
    ]
];