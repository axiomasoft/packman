<?php

declare(strict_types=1);

return [
    'connection' => env('PACKMAN_MODULES_CONNECTION'),

    'cache' => [
        'store' => env('PACKMAN_MODULES_CACHE_STORE'),
        'ttl' => (int) env('PACKMAN_MODULES_CACHE_TTL', 3600),
        'prefix' => env('PACKMAN_MODULES_CACHE_PREFIX', 'modules'),
    ],

    'package_types' => [
        'packman-module',
        'packman-plugin',
        'module',
        'plugin',
    ],

    'extra_keys' => [
        ['packman', 'module'],
        ['packman', 'plugin'],
        ['module'],
        ['plugin'],
    ],

    'cache_path' => 'bootstrap/cache/modules.php',

    'lock_key' => 'modules:lifecycle-graph',

    'compiler_package' => 'axiomasoft/packman-modules',

    'tables' => [
        'modules' => 'mod_modules',
        'records' => 'mod_records',
        'lifecycle_log' => 'mod_lifecycle_log',
    ],

    'registry_schema' => 'packman-registry/1',

    'compile_command' => 'modules:compile',
];
