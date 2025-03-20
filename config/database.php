<?php

return [
    'default' => env('DB_CONNECTION', 'crm'),

    'connections' => [

        'crm' => [
            'driver'    => 'mysql',
            'host'      => env('DB_HOST', '127.0.0.1'),
            'port'      => env('DB_PORT', '3306'),
            'database'  => env('DB_DATABASE', 'crm'),
            'username'  => env('DB_USERNAME', 'crmomg'),
            'password'  => env('DB_PASSWORD', '-@g.lozano1321@-'),
            'charset'   => 'utf8',
            'collation' => 'utf8_unicode_ci',
            'prefix'    => '',
            'strict'    => false,
        ],
        
        'afa' => [
            'driver'    => 'mysql',
            'host'      => env('DB_HOST_AFA', '127.0.0.1'),
            'port'      => env('DB_PORT_AFA', '3306'),
            'database'  => env('DB_DATABASE_AFA', 'crm_afa'),
            'username'  => env('DB_USERNAME_AFA', 'crmomg'),
            'password'  => env('DB_PASSWORD_AFA', '-@g.lozano1321@-'),
            'charset'   => 'utf8',
            'collation' => 'utf8_unicode_ci',
            'prefix'    => '',
            'strict'    => false,
        ],

        'sl' => [
            'driver'    => 'mysql',
            'host'      => env('DB_HOST_SL', '127.0.0.1'),
            'port'      => env('DB_PORT_SL', '3306'),
            'database'  => env('DB_DATABASE_SL', 'crm_sl'),
            'username'  => env('DB_USERNAME_SL', 'crmomg'),
            'password'  => env('DB_PASSWORD_SL', '-@g.lozano1321@-'),
            'charset'   => 'utf8',
            'collation' => 'utf8_unicode_ci',
            'prefix'    => '',
            'strict'    => false,
        ],

    ],

];
