<?php

declare(strict_types=1);

use Packman\Modules\Data\Compiler\ExtensionStamp;
use Packman\Modules\Testing\ExtensionContract;

require_once __DIR__.'/../Fixtures/extension-contract/fixture-adapter.php';

function extensionFixturePath(string $name): string
{
    return __DIR__.'/../Fixtures/extension-contract/'.$name.'.json';
}

it('verifies deterministic compiled extension snapshots and explicit version metadata', function (string $fixture): void {
    [$manifests, $versions] = extensionContractFixture(extensionFixturePath($fixture));
    $report = ExtensionContract::verify(manifests: $manifests, installedPackages: $versions);

    expect($report->passed())->toBeTrue($report->summary())
        ->and($report->fingerprint)->toMatch('/^[a-f0-9]{64}$/')
        ->and($report->summary())->toContain('Extension contract passed');
})->with(['positive', 'replacement']);

it('reports duplicate owners and replacement cycles from scalar fixtures', function (string $fixture): void {
    $path = extensionFixturePath($fixture);
    $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    [$manifests, $versions] = extensionContractFixture($path);
    $report = ExtensionContract::verify(manifests: $manifests, installedPackages: $versions);

    expect($report->passed())->toBeFalse();
    foreach ($data['contains'] as $needle) {
        expect($report->summary())->toContain($needle);
    }
})->with(['duplicate', 'replacement-cycle']);

it('pins durable replay to exact point id and version while retaining fingerprint provenance', function (): void {
    $stored = new ExtensionStamp('wakeup.handler', 'notification.digest.v1', 'notification-digest/1', 'registry-a');
    $unrelatedChange = new ExtensionStamp('wakeup.handler', 'notification.digest.v1', 'notification-digest/1', 'registry-b');
    $versionChange = new ExtensionStamp('wakeup.handler', 'notification.digest.v1', 'notification-digest/2', 'registry-a');

    expect(ExtensionContract::sameImplementation($stored, $unrelatedChange))->toBeTrue()
        ->and(ExtensionContract::sameImplementation($stored, $versionChange))->toBeFalse();
});

it('reports invalid installed metadata without reading the host installation', function (): void {
    [$manifests] = extensionContractFixture(extensionFixturePath('positive'));
    $report = ExtensionContract::verify(manifests: $manifests, installedPackages: ['wrong/name']);

    expect($report->passed())->toBeTrue($report->summary());
});
