<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

use Filament\Panel;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Packman\Plugins\Contracts\Contributions\Capabilities;
use Packman\Plugins\Contracts\Registry\PluginInspector;
use Packman\Plugins\Laravel\ExtensionRegistrar;
use Packman\Schema\Laravel\SchemaServiceProvider;
use PackmanEcosystemFixture\Extra;
use PackmanEcosystemFixture\ExtraPlugin;
use PackmanEcosystemFixture\FixtureServiceProvider;
use PackmanEcosystemFixture\Greeting;
use PackmanEcosystemFixture\GreetingPlugin;

function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function host(string $name, array $disabled): Application
{
    $app = require __DIR__.'/bootstrap/app.php';
    $app->afterBootstrapping(LoadConfiguration::class, static function (Application $app) use ($name, $disabled): void {
        $app['config']->set('database.connections', []);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('fixture.name', $name);
        $app['config']->set('packman.disabled_plugins', $disabled);
    });
    $app->make(Kernel::class)->bootstrap();

    return $app;
}

$a = host('first', []);
$greeting = $a->make(Capabilities::class)->require(Greeting::class);
$inspector = $a->make(PluginInspector::class);
check($greeting->text() === 'Hello first', 'First host operation failed');
check($a->make(Capabilities::class)->has(Extra::class), 'First host lost optional capability');
$b = host('second', ['fixture.extra']);
check($b->make(Capabilities::class)->require(Greeting::class)->text() === 'Hello second', 'Second host configuration leaked');
check(! $b->make(Capabilities::class)->has(Extra::class), 'Second host ignored activation');
// Repeat provider and declaration registration after both applications exist.
$a->register(FixtureServiceProvider::class, force: true);
ExtensionRegistrar::builtInFor($a, GreetingPlugin::class, ExtraPlugin::class);
check($a->make(PluginInspector::class) === $inspector, 'Repeat registration replaced the sealed graph');
check($greeting->text() === 'Hello first', 'Retained first-host service used the second host');
check($a->make(Capabilities::class)->has(Extra::class), 'First-host activation was overwritten');
check($a['db']->getConnections() === [] && $b['db']->getConnections() === [], 'No-database host opened a connection');
check(! class_exists(SchemaServiceProvider::class), 'Minimal host unexpectedly installed schema');
check(! class_exists(Panel::class), 'Minimal host unexpectedly installed Filament');

echo json_encode(['operation' => $greeting->text(), 'second_operation' => 'Hello second', 'simultaneous_hosts' => true, 'database_connections' => [], 'schema_installed' => false, 'filament_installed' => false], JSON_THROW_ON_ERROR).PHP_EOL;
