<?php

return [
    'base_url' => env('NEXFIRA_BASE_URL', 'https://hub.nexfira.mx'),
    'integration_token' => env('NEXFIRA_INTEGRATION_TOKEN', ''),
    'issuer_id' => env('NEXFIRA_ISSUER_ID', ''),
    'expedition_postal_code' => env('NEXFIRA_EXPEDITION_POSTAL_CODE', ''),
    'fiscal_timezone' => env('NEXFIRA_FISCAL_TIMEZONE', 'America/Mexico_City'),
    'tax_rate' => env('NEXFIRA_TAX_RATE', '0.160000'),
    'request_timeout' => (int) env('NEXFIRA_REQUEST_TIMEOUT', 30),
    'polling' => [
        'enabled' => filter_var(env('NEXFIRA_POLLING_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'batch_size' => max(1, (int) env('NEXFIRA_POLLING_BATCH_SIZE', 25)),
    ],
    'folio' => [
        'sequence_key' => 'nexfira_cfdi_ingreso',
        'initial' => 40000,
    ],
    'series' => [
        'ELEKTRA' => 'ELK',
        'MLG' => 'MLG',
        'PISO DE VENTA' => 'PV',
        'MERCADOLIBRE' => 'F-ML',
        'CYBERPUERTA' => 'C',
    ],
    'global' => [
        'receiver_rfc' => env('NEXFIRA_GLOBAL_RECEIVER_RFC', 'XAXX010101000'),
        'receiver_name' => env('NEXFIRA_GLOBAL_RECEIVER_NAME', 'PUBLICO EN GENERAL'),
        'receiver_fiscal_regime' => env('NEXFIRA_GLOBAL_RECEIVER_REGIME', '616'),
        'receiver_postal_code' => env('NEXFIRA_GLOBAL_RECEIVER_POSTAL_CODE', ''),
        'cfdi_use' => env('NEXFIRA_GLOBAL_CFDI_USE', 'S01'),
        'product_code' => env('NEXFIRA_GLOBAL_PRODUCT_CODE', '01010101'),
        'unit_code' => env('NEXFIRA_GLOBAL_UNIT_CODE', 'ACT'),
        // Valores iniciales; se respetan método y forma seleccionados por la facturista.
        'payment_method' => 'PUE',
        'payment_form' => '31',
    ],
];
