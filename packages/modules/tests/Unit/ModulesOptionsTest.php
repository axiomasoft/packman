<?php

declare(strict_types=1);

use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Modules\Internal\Support\ModulesOptions;

it('allows a null module connection and exposes universal defaults', function (): void {
    $options = ModulesOptions::fromArray(['connection' => null]);

    expect($options->connection)->toBeNull()
        ->and($options->cacheTtl)->toBe(3600)
        ->and($options->cachePrefix)->toBe('modules')
        ->and($options->packageTypes)->toBe(['packman-module', 'packman-plugin', 'module', 'plugin'])
        ->and($options->extraKeys)->toBe([['packman', 'module'], ['packman', 'plugin'], ['module'], ['plugin']])
        ->and($options->cachePath)->toBe('bootstrap/cache/modules.php')
        ->and($options->lockKey)->toBe('modules:lifecycle-graph')
        ->and($options->compilerPackage)->toBe('axiomasoft/packman-modules')
        ->and($options->modulesTable)->toBe('mod_modules')
        ->and($options->recordsTable)->toBe('mod_records')
        ->and($options->lifecycleLogTable)->toBe('mod_lifecycle_log')
        ->and($options->registrySchema)->toBe('packman-registry/1')
        ->and($options->compileCommand)->toBe('modules:compile');
});

it('rejects a zero or negative cache ttl', function (int $ttl): void {
    try {
        ModulesOptions::fromArray(['cache' => ['ttl' => $ttl]]);
        $this->fail('Invalid configuration was accepted.');
    } catch (InvalidConfiguration $exception) {
        expect($exception->violations)->toContain('cache.ttl: must be a positive integer');
    }
})->with([0, -1]);

it('accepts custom package types, extra keys, and storage options', function (): void {
    $options = ModulesOptions::fromArray([
        'package_types' => ['vendor-plugin'],
        'extra_keys' => ['vendor.plugin'],
        'cache_path' => 'bootstrap/cache/custom_modules.php',
        'lock_key' => 'custom:lock',
        'compiler_package' => 'vendor/host',
        'tables' => [
            'modules' => 'host_modules',
            'records' => 'host_records',
            'lifecycle_log' => 'host_lifecycle_log',
        ],
    ]);

    expect($options->packageTypes)->toBe(['vendor-plugin'])
        ->and($options->extraKeys)->toBe([['vendor', 'plugin']])
        ->and($options->cachePath)->toBe('bootstrap/cache/custom_modules.php')
        ->and($options->lockKey)->toBe('custom:lock')
        ->and($options->compilerPackage)->toBe('vendor/host')
        ->and($options->modulesTable)->toBe('host_modules')
        ->and($options->recordsTable)->toBe('host_records')
        ->and($options->lifecycleLogTable)->toBe('host_lifecycle_log');
});
