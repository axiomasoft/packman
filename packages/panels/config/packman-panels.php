<?php

declare(strict_types=1);

return [
    'provider_tag' => 'packman.panels.providers',

    'views' => [
        'connection' => env('PACKMAN_PANELS_VIEWS_CONNECTION'),
        'table' => 'sys_saved_views',
        'scopes_table' => 'sys_saved_view_scopes',
    ],

    'defaults' => [
        'resource_prefix' => true,
        'cache' => [
            'store' => null,
            'ttl' => 300,
            'generation' => 1,
        ],
        'consistency' => [
            'reads' => 'primary',
            'state_refresh' => 'request',
        ],
        'trace_decisions' => false,
    ],
];
