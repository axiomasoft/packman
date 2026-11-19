<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Console\Application as Artisan;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Extensions\Concerns\Configuration\Configurable;
use Packman\Extensions\Concerns\Persistence\OwnsSchema;
use Packman\Extensions\Contracts\Configuration\WithConfiguration;
use Packman\Extensions\Contracts\Persistence\WithSchema;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Contracts\Registry\PluginInspector;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Laravel\ExtensionRegistrar;
use Packman\Plugins\Laravel\PluginRegistrar;
use Packman\Plugins\Laravel\PluginsServiceProvider;
use Packman\Plugins\Testing\PluginGraph;

final readonly class HostSchemaPlugin implements ExtensionPlugin, WithConfiguration, WithSchema
{
    use Configurable;
    use OwnsSchema;

    public function __construct(private ?string $prefix = null, private string $id = 'acme.host') {}

    public function manifest(): PluginManifest
    {
        return new PluginManifest($this->id);
    }

    public function tablePrefix(): string
    {
        return $this->prefix ?? throw new LogicException('Discovery inspected a schema facet');
    }

    public function configRoot(): string
    {
        return $this->prefix ?? throw new LogicException('Discovery inspected configuration');
    }

    public function register(PluginRegistrar $registrar): void
    {
        if ($this->prefix === null) {
            throw new LogicException('Discovery registered a plugin');
        }
    }
}

it('uses each active host binding for early configuration and compiled facets', function (): void {
    $host = function (string $prefix, bool $disabled = false): Application {
        $app = new Application($this->app->basePath());
        $app->instance('config', new Repository(['packman' => ['disabled_plugins' => $disabled ? ['acme.host'] : []]]));
        $app->register(PluginsServiceProvider::class);
        $app->bind(HostSchemaPlugin::class, static fn (): HostSchemaPlugin => $disabled
            ? throw new LogicException('Resolved a disabled binding')
            : new HostSchemaPlugin($prefix));
        ExtensionRegistrar::builtInFor($app, HostSchemaPlugin::class);
        $app->boot();

        return $app;
    };

    try {
        $a = $host('alpha');
        $b = $host('beta');
        $disabled = $host('', true);
        $prefix = static fn (Application $app): ?string => $app->make(PluginInspector::class)->facetsOf('acme.host')?->tablePrefix;
        expect([$prefix($a), $prefix($b), $prefix($disabled), $prefix($a)])->toBe(['alpha', 'beta', null, 'alpha'])
            ->and($disabled->make(PluginInspector::class)->disabledIds())->toBe(['acme.host']);
    } finally {
        Container::setInstance($this->app);
    }
});

it('rejects an active binding that changes the discovered manifest', function (): void {
    $container = new Container;
    $container->instance(HostSchemaPlugin::class, new HostSchemaPlugin('prefix', 'acme.changed'));

    expect(fn () => PluginGraph::compile($container, [HostSchemaPlugin::class]))->toThrow(InvalidConfiguration::class, 'preserve its class and manifest');
});

it('adds plugin console commands only to the Artisan application of their host', function (): void {
    $newHost = function (bool $plugins): Artisan {
        $app = new Application($this->app->basePath());
        $app->instance('config', new Repository);

        if ($plugins) {
            $app->register(PluginsServiceProvider::class);
        }
        $app->boot();

        return (new Artisan($app, $app['events'], $app->version()))->setContainerCommandLoader();
    };

    try {
        $a = $newHost(true);
        $b = $newHost(false);
        expect($a->has('packman:plugins'))->toBeTrue()
            ->and($b->has('packman:plugins'))->toBeFalse();
    } finally {
        Container::setInstance($this->app);
    }
});
