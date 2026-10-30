<?php

declare(strict_types=1);

use Ownswitch\Support\Env;

return [
    'name'     => Env::get('APP_NAME', 'Ownswitch'),
    'env'      => Env::get('APP_ENV', 'production'),
    'debug'    => (bool) Env::get('APP_DEBUG', false),
    'key'      => (string) Env::get('APP_KEY', ''),
    'timezone' => Env::get('APP_TIMEZONE', 'UTC'),

    // Defaults used when system_settings has no value (BILLING_RULES.md section 3, global defaults).
    'defaults' => [
        'currency'                 => 'USD',
        'customer_initial'         => 6,
        'customer_subsequent'      => 6,
        'carrier_initial'          => 6,
        'carrier_subsequent'       => 6,
        'prefer_portal_rating'     => true,
        'charge_connect_on_zero'   => false,
        'prepaid_hard_cap_seconds' => 7200,
        'media_policy'             => 'relay',
    ],

    'integrations' => [
        'opensips' => [
            'enabled'    => (bool) Env::get('OPENSIPS_ENABLED', false),
            'sip_domain' => Env::get('OPENSIPS_SIP_DOMAIN', 'sip.example.test'),
            'mi_url'     => Env::get('OPENSIPS_MI_URL', ''),
            'db' => [
                'host'     => Env::get('OPENSIPS_DB_HOST', '127.0.0.1'),
                'port'     => (int) Env::get('OPENSIPS_DB_PORT', 3306),
                'database' => Env::get('OPENSIPS_DB_DATABASE', 'opensips'),
                'username' => Env::get('OPENSIPS_DB_USERNAME', ''),
                'password' => Env::get('OPENSIPS_DB_PASSWORD', ''),
            ],
        ],
        'cgrates' => [
            'url'      => Env::get('CGRATES_URL', ''),
            'user'     => Env::get('CGRATES_USER', ''),
            'password' => Env::get('CGRATES_PASSWORD', ''),
            'tenant'   => Env::get('CGRATES_TENANT', 'cgrates.org'),
        ],
        'rtpengine' => [
            'host' => Env::get('RTPENGINE_HOST', ''),
            'port' => (int) Env::get('RTPENGINE_PORT', 22222),
        ],
        'freeswitch' => [
            'host'     => Env::get('FREESWITCH_HOST', ''),
            'port'     => (int) Env::get('FREESWITCH_PORT', 8021),
            'password' => Env::get('FREESWITCH_PASSWORD', ''),
        ],
    ],

    'recordings_dir' => Env::get('RECORDINGS_DIR', ''),
];
