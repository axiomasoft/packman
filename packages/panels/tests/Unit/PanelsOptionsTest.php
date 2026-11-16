<?php

declare(strict_types=1);

use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Panels\Enums\Panel\Reads;
use Packman\Panels\Enums\Panel\StateRefresh;
use Packman\Panels\Internal\Configuration\PanelsOptions;

it('passes on an empty environment with the shipped provider tag and defaults', function (): void {
    $options = PanelsOptions::fromArray([]);

    expect($options->providerTag)->toBe('packman.panels.providers')
        ->and($options->viewsConnection)->toBeNull()
        ->and($options->viewsTable)->toBe('sys_saved_views')
        ->and($options->scopesTable)->toBe('sys_saved_view_scopes')
        ->and($options->defaults)->toBe([]);
});

it('accumulates shape violations without revealing values', function (): void {
    expect(static fn (): PanelsOptions => PanelsOptions::fromArray(['provider_tag' => '']))
        ->toThrow(InvalidConfiguration::class, 'provider_tag');

    try {
        PanelsOptions::fromArray(['provider_tag' => '', 'defaults' => ['consistency' => ['reads' => 'invalid']]]);
        test()->fail('Invalid configuration was accepted.');
    } catch (InvalidConfiguration $exception) {
        expect($exception->violations)->toContain('provider_tag: must be a non-empty string')
            ->toContain('defaults.consistency.reads: must be "primary" or "default"');
    }
});

it('parses custom provider tags, views storage, and panel defaults', function (): void {
    $options = PanelsOptions::fromArray([
        'provider_tag' => 'app.panels',
        'views' => [
            'connection' => 'pinned',
            'table' => 'custom_views',
            'scopes_table' => 'custom_scopes',
        ],
        'defaults' => [
            'resource_prefix' => 'admin_prefix',
            'cache' => ['store' => 'redis', 'ttl' => 600, 'generation' => 2],
            'consistency' => ['reads' => Reads::Primary->value, 'state_refresh' => StateRefresh::Check->value],
            'trace_decisions' => true,
        ],
    ]);

    expect($options->providerTag)->toBe('app.panels')
        ->and($options->viewsConnection)->toBe('pinned')
        ->and($options->viewsTable)->toBe('custom_views')
        ->and($options->scopesTable)->toBe('custom_scopes')
        ->and($options->defaults)->toBe([
            'resource_prefix' => 'admin_prefix',
            'cache.store' => 'redis',
            'cache.ttl' => 600,
            'cache.generation' => 2,
            'consistency.reads' => 'primary',
            'consistency.state_refresh' => 'check',
            'trace_decisions' => true,
        ]);
});
