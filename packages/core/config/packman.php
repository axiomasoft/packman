<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Unsafe Configuration Guard
    |--------------------------------------------------------------------------
    |
    | Allows explicitly acknowledged unsafe settings in production when true.
    |
    */
    'unsafe' => [
        'allow_in_production' => (bool) env('PACKMAN_ALLOW_UNSAFE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Error Problem Registry URN Prefix
    |--------------------------------------------------------------------------
    |
    | Base URN prefix used when compiling ProblemDefinition entries.
    |
    */
    'errors' => [
        'urn_prefix' => 'urn:packman:problem:',
    ],

    /*
    |--------------------------------------------------------------------------
    | Acknowledged Dangerous Modes
    |--------------------------------------------------------------------------
    |
    | Dotted config paths explicitly acknowledged by operators.
    |
    */
    'acknowledged_dangerous_modes' => [],
];
