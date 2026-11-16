<?php

declare(strict_types=1);

use Packman\Modules\Data\ExtensionStamp;
use Packman\Modules\Data\ModuleManifest;
use Packman\Modules\Exceptions\InvalidManifestException;
use Packman\Modules\Internal\Compiler\InstalledPackages;

it('reads installed version metadata and prefers the source reference', function (): void {
    $path = installedMetadataFixture([
        'packages' => [
            [
                'name' => 'acme/beta',
                'version' => '2.0.0',
                'source' => ['reference' => 'source-ref'],
                'dist' => ['reference' => 'dist-ref', 'shasum' => 'ignored'],
                'install-path' => '../beta',
                'time' => '2026-01-01T00:00:00+00:00',
            ],
            [
                'name' => 'acme/alpha',
                'version' => '1.0.0',
                'dist' => ['reference' => 'only-dist'],
            ],
            [
                'name' => 'acme/unversioned',
            ],
            [
                'name' => 'acme/blank-ref',
                'version' => '3.0.0',
                'source' => ['reference' => '   '],
                'dist' => ['reference' => ''],
            ],
        ],
    ]);

    $versions = InstalledPackages::discoverVersions($path);

    expect(array_keys($versions))->toBe(['acme/alpha', 'acme/beta', 'acme/blank-ref'])
        ->and($versions['acme/beta']->canonical())->toBe([
            'name' => 'acme/beta',
            'version' => '2.0.0',
            'reference' => 'source-ref',
        ])
        ->and($versions['acme/alpha']->reference)->toBe('only-dist')
        ->and($versions['acme/blank-ref']->reference)->toBeNull()
        ->and(InstalledPackages::discover($path))->toBe([
            'acme/beta',
            'acme/alpha',
            'acme/unversioned',
            'acme/blank-ref',
        ]);
});

it('normalizes a missing installed file and a list-shaped manifest', function (): void {
    expect(InstalledPackages::discoverVersions('/tmp/packman-missing-installed.json'))->toBe([]);

    $path = installedMetadataFixture([
        [
            'name' => 'acme/list',
            'version' => '9.9.9',
        ],
    ]);

    expect(InstalledPackages::discoverVersions($path)['acme/list']->reference)->toBeNull()
        ->and(InstalledPackages::discover($path))->toBe(['acme/list']);
});

it('accumulates repeatable replacement directives and rejects empty identity', function (): void {
    $manifest = ModuleManifest::make('example/alpha')
        ->replace(point: 'menu', targetId: 'crm:old', replacementId: 'crm:new')
        ->replace(point: 'entity', targetId: 'crm.lead', replacementId: 'crm.deal');

    expect($manifest->replacements)->toHaveCount(2)
        ->and($manifest->replacements[0]->point)->toBe('menu')
        ->and($manifest->replacements[0]->targetId)->toBe('crm:old')
        ->and($manifest->replacements[0]->replacementId)->toBe('crm:new')
        ->and($manifest->replacements[1]->point)->toBe('entity');

    expect(fn () => ModuleManifest::make('example/alpha')->replace(point: ' ', targetId: 'a', replacementId: 'b'))
        ->toThrow(InvalidManifestException::class, 'point');
});

it('round-trips an extension stamp and rejects invalid scalars', function (): void {
    $stamp = new ExtensionStamp(
        point: 'workflow.step',
        id: 'App\\Steps\\Approve',
        version: '1.4.0',
        registryFingerprint: 'abc123',
    );

    expect(ExtensionStamp::fromArray($stamp->toArray())->toArray())->toBe($stamp->toArray());

    expect(fn () => ExtensionStamp::fromArray([
        'point' => 'menu',
        'id' => 'crm:home',
        'version' => '',
        'registryFingerprint' => 'abc',
    ]))->toThrow(InvalidArgumentException::class, 'version');

    expect(fn () => new ExtensionStamp(
        point: 'menu',
        id: 'crm:home',
        version: '1',
        registryFingerprint: '   ',
    ))->toThrow(InvalidArgumentException::class, 'registryFingerprint');

    expect(fn () => ExtensionStamp::fromArray([
        'point' => 'menu',
        'id' => 1,
        'version' => '1',
        'registryFingerprint' => 'abc',
    ]))->toThrow(InvalidArgumentException::class, 'id');
});

/** @param array<mixed> $payload */
function installedMetadataFixture(array $payload): string
{
    $path = tempnam(sys_get_temp_dir(), 'packman-installed-');

    if ($path === false) {
        throw new RuntimeException('Unable to create an installed.json fixture.');
    }

    file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR));

    return $path;
}
