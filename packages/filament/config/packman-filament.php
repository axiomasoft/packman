<?php

declare(strict_types=1);

return [
    'navigation_group' => 'Packman',

    'guard_panel' => null,

    'enforce' => false,

    'resources' => [],

    'abilities' => [
        'view_any',
        'view',
        'create',
        'update',
        'delete',
        'delete_any',
        'restore',
        'restore_any',
        'force_delete',
        'force_delete_any',
        'replicate',
        'reorder',
    ],

    'exclude' => [
        'resources' => [],
        'pages' => [],
        'widgets' => [],
    ],

    'persistent_middleware' => [],

    'refuse_unverifiable_downloads' => true,
];
