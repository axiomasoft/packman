<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as RepositoryContract;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Plugins\Contracts\ExtensionPlugin;
use Packman\Plugins\Data\PluginManifest;
use Packman\Plugins\Enums\Cardinality;
use Packman\Plugins\Exceptions\MissingExtensionException;
use Packman\Plugins\Internal\CapabilitiesRegistry;
use Packman\Plugins\Internal\PluginEntry;
use Packman\Plugins\Internal\PluginGraphCompiler;
use Packman\Plugins\Laravel\PluginRegistrar;
use Packman\Plugins\Tests\Fixtures\Greeter;
use Packman\Plugins\Tests\Fixtures\HelloGreeter;
use Packman\Plugins\Tests\Fixtures\LoudGreeter;

require_once __DIR__.'/../Fixtures/TestContracts.php';

final class CapHostRequiredPlugin implements ExtensionPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest('cap.host', '1.0.0');
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->defineCapability(Greeter::class, Cardinality::RequiredSingle);
    }
}

final class CapHostOptionalPlugin implements ExtensionPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest('cap.host', '1.0.0');
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->defineCapability(Greeter::class, Cardinality::OptionalSingle);
    }
}

final class CapHostManyPlugin implements ExtensionPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest('cap.host', '1.0.0');
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->defineCapability(Greeter::class, Cardinality::Many);
    }
}

final class CapHelloProviderPlugin implements ExtensionPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest('cap.provider', '1.0.0', requires: ['cap.host']);
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->provides(Greeter::class, HelloGreeter::class);
    }
}

final class CapManyProvidersPlugin implements ExtensionPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest('cap.many', '1.0.0', requires: ['cap.host']);
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->provides(Greeter::class, HelloGreeter::class)
            ->provides(Greeter::class, LoudGreeter::class);
    }
}

final class CapConfigNoDefaultPlugin implements ExtensionPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest('cap.config', '1.0.0', requires: ['cap.host']);
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->providesFromConfig(Greeter::class, 'acme.greeter');
    }
}

final class CapConfigWithDefaultPlugin implements ExtensionPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest('cap.config', '1.0.0', requires: ['cap.host']);
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->providesFromConfig(Greeter::class, 'acme.greeter', HelloGreeter::class);
    }
}

/**
 * @param  list<class-string<ExtensionPlugin>>  $plugins
 * @param  array<string, mixed>  $config
 */
function buildCapabilities(array $plugins, array $config = []): CapabilitiesRegistry
{
    $container = new Container;
    $container->instance(RepositoryContract::class, new Repository($config));

    $entries = array_map(static fn (string $class, int $idx): PluginEntry => PluginEntry::config($class, $idx), $plugins, array_keys($plugins));
    $compiled = (new PluginGraphCompiler($container))->compile($entries);

    return new CapabilitiesRegistry($compiled, $container);
}

it('resolves the single provider of a required capability', function (): void {
    $capabilities = buildCapabilities([CapHostRequiredPlugin::class, CapHelloProviderPlugin::class]);

    expect($capabilities->has(Greeter::class))->toBeTrue()
        ->and($capabilities->require(Greeter::class))->toBeInstanceOf(HelloGreeter::class);
});

it('fails compilation when a required single capability has no provider', function (): void {
    expect(static fn (): CapabilitiesRegistry => buildCapabilities([CapHostRequiredPlugin::class]))
        ->toThrow(InvalidConfiguration::class, 'has 0 active providers');
});

it('returns false and throws MissingExtensionException when optional capability has no provider', function (): void {
    $capabilities = buildCapabilities([CapHostOptionalPlugin::class]);

    expect($capabilities->has(Greeter::class))->toBeFalse()
        ->and($capabilities->has(LoudGreeter::class))->toBeFalse()
        ->and(static fn (): object => $capabilities->require(Greeter::class))->toThrow(MissingExtensionException::class);
});

it('reads a config-chosen provider when resolved, falling back to its default', function (): void {
    expect(buildCapabilities([CapHostOptionalPlugin::class, CapConfigNoDefaultPlugin::class])->has(Greeter::class))->toBeFalse()
        ->and(buildCapabilities([CapHostOptionalPlugin::class, CapConfigNoDefaultPlugin::class], ['acme' => ['greeter' => '']])->has(Greeter::class))->toBeFalse()
        ->and(buildCapabilities([CapHostOptionalPlugin::class, CapConfigNoDefaultPlugin::class], ['acme' => ['greeter' => LoudGreeter::class]])->require(Greeter::class))
        ->toBeInstanceOf(LoudGreeter::class)
        ->and(buildCapabilities([CapHostOptionalPlugin::class, CapConfigWithDefaultPlugin::class])->require(Greeter::class))->toBeInstanceOf(HelloGreeter::class)
        ->and(buildCapabilities([CapHostOptionalPlugin::class, CapConfigWithDefaultPlugin::class], ['acme' => ['greeter' => LoudGreeter::class]])->require(Greeter::class))
        ->toBeInstanceOf(LoudGreeter::class);
});

it('resolves every provider of a many capability and refuses require()', function (): void {
    $capabilities = buildCapabilities([CapHostManyPlugin::class, CapManyProvidersPlugin::class]);

    expect(array_map(static fn (Greeter $g): string => $g->greet(), $capabilities->all(Greeter::class)))->toBe(['hello', 'HELLO'])
        ->and(static fn (): object => $capabilities->require(Greeter::class))->toThrow(MissingExtensionException::class, 'takes many providers');
});
