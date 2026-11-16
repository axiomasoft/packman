<?php

declare(strict_types=1);

use Packman\Plugins\Contracts\ExtensionPlugin;
use Packman\Plugins\Data\PluginManifest;
use Packman\Plugins\Internal\ContributionChecks;
use Packman\Plugins\Laravel\PluginRegistrar;
use Packman\Plugins\Tests\Fixtures\Greeter;
use Packman\Plugins\Tests\Fixtures\HelloGreeter;
use Packman\Plugins\Tests\Fixtures\NotAGreeter;

require_once __DIR__.'/../Fixtures/TestContracts.php';

final class ZeroArgPluginStub implements ExtensionPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest('acme.zero');
    }

    public function register(PluginRegistrar $registrar): void {}
}

final class NeedsArgsPluginStub implements ExtensionPlugin
{
    public function __construct(public string $dep) {}

    public function manifest(): PluginManifest
    {
        return new PluginManifest('acme.needs-args');
    }

    public function register(PluginRegistrar $registrar): void {}
}

it('detects duplicate contributions, invalid class hierarchy, foreign namespaces, and non-instantiable handlers', function (): void {
    $problems = [];
    $checks = new ContributionChecks($problems);
    $checks->registerOwner('acme.a', ZeroArgPluginStub::class);
    $checks->registerOwner('acme.b', ZeroArgPluginStub::class);

    expect($checks->claim('stage', 'acme.validate', 'acme.a'))->toBeTrue()
        ->and($checks->claim('stage', 'acme.validate', 'acme.b'))->toBeFalse()
        ->and($checks->instantiable(HelloGreeter::class))->toBeTrue()
        ->and($checks->instantiable(NeedsArgsPluginStub::class))->toBeFalse();

    $node = ['class' => ZeroArgPluginStub::class, 'manifest' => new PluginManifest('acme.zero')];
    $checks->checkClass('acme.zero', $node, 'greeter', NotAGreeter::class, Greeter::class);
    $checks->checkClass('acme.zero', $node, 'greeter', HelloGreeter::class, Greeter::class);

    expect($problems)->toHaveCount(3)
        ->and($problems[0])->toContain('PLUGIN_DUPLICATE_CONTRIBUTION stage acme.validate')
        ->and($problems[1])->toContain('PLUGIN_INVALID_CONTRIBUTION acme.zero greeter')
        ->and($problems[2])->toContain('PLUGIN_FOREIGN_NAMESPACE acme.zero greeter');
});
