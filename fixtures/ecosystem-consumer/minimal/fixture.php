<?php

declare(strict_types=1);

namespace PackmanEcosystemFixture;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\ServiceProvider;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Enums\Contributions\Cardinality;
use Packman\Plugins\Laravel\ExtensionRegistrar;
use Packman\Plugins\Laravel\PluginRegistrar;

interface Greeting
{
    public function text(): string;
}

final readonly class HostGreeting implements Greeting
{
    public function __construct(private Repository $config) {}

    public function text(): string
    {
        return 'Hello '.$this->config->get('fixture.name');
    }
}

interface Extra {}

final class HostExtra implements Extra {}

final class GreetingPlugin implements ExtensionPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest('fixture.greeting');
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->defineCapability(Greeting::class, Cardinality::RequiredSingle);
        $registrar->provides(Greeting::class, HostGreeting::class);
    }
}

final class ExtraPlugin implements ExtensionPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest('fixture.extra');
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->defineCapability(Extra::class, Cardinality::OptionalSingle);
        $registrar->provides(Extra::class, HostExtra::class);
    }
}

final class FixtureServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        ExtensionRegistrar::builtInFor($this->app, GreetingPlugin::class, ExtraPlugin::class);
    }
}
