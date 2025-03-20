<?php

return [
    'default' => env('DB_CONNECTION', 'crm'),

    'connections' => [

        'crm' => [
            'driver'    => 'mysql',
            'host'      => env('DB_HOST', '201.7.208.7'),
            'port'      => env('DB_PORT', '21227'),
            'database'  => env('DB_DATABASE', 'crm-afa'),
            'username'  => env('DB_USERNAME', 'afacrm'),
            'password'  => env('DB_PASSWORD', 'rsOp7Ei840F7'),
            'charset'   => 'utf8',
            'collation' => 'utf8_unicode_ci',
            'prefix'    => '',
            'strict'    => false,
        ],
        
        'afa' => [
            'driver'    => 'mysql',
            'host'      => env('DB_HOST_AFA', '201.7.208.7'),
            'port'      => env('DB_PORT_AFA', '21227'),
            'database'  => env('DB_DATABASE_AFA', 'crm-afa'),
            'username'  => env('DB_USERNAME_AFA', 'afacrm'),
            'password'  => env('DB_PASSWORD_AFA', 'rsOp7Ei840F7'),
            'charset'   => 'utf8',
            'collation' => 'utf8_unicode_ci',
            'prefix'    => '',
            'strict'    => false,
        ],

        'sl' => [
            'driver'    => 'mysql',
            'host'      => env('DB_HOST_SL', '201.7.208.7'),
            'port'      => env('DB_PORT_SL', '21227'),
            'database'  => env('DB_DATABASE_SL', 'crm-sl'),
            'username'  => env('DB_USERNAME_SL', 'afacrm'),
            'password'  => env('DB_PASSWORD_SL', 'rsOp7Ei840F7'),
            'charset'   => 'utf8',
            'collation' => 'utf8_unicode_ci',
            'prefix'    => '',
            'strict'    => false,
        ],

    ],

];
