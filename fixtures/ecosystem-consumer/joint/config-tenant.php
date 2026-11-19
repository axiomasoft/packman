<?php

declare(strict_types=1);

return [
    'runtime_profile' => 'laravel-scoped',
    'facilities' => ['database' => false, 'cache' => false, 'session' => false, 'storage' => false],
    'shared' => [
        'database' => ['owner' => 'host:eloquent', 'purpose' => 'host:data-isolation'],
        'cache' => ['owner' => 'host:cache', 'purpose' => 'host:cache-isolation'],
        'session' => ['owner' => 'host:session', 'purpose' => 'host:authentication'],
        'storage' => ['owner' => 'host:storage', 'purpose' => 'host:storage-isolation'],
    ],
];
