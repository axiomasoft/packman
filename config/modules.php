<?php

declare(strict_types=1);

use Packman\Modules\Contracts\Definition\ModuleDiscovery;

return [
    'connection' => env('PACKMAN_MODULES_CONNECTION'),

    'cache' => [
        'store' => env('PACKMAN_MODULES_CACHE_STORE'),
        'ttl' => (int) env('PACKMAN_MODULES_CACHE_TTL', 3600),
        'prefix' => env('PACKMAN_MODULES_CACHE_PREFIX', 'modules'),
    ],

    'package_types' => [...ModuleDiscovery::DEFAULT_PACKAGE_TYPES],

    'extra_keys' => [...ModuleDiscovery::DEFAULT_EXTRA_KEYS],

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
