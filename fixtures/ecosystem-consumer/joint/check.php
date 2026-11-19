<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

use Commerz\Identity\SellableRef;
use Commerz\Inventory\Allocation\Contracts\AllocationStrategy;
use Commerz\Inventory\Allocation\Data\AllocationNeed;
use Commerz\Inventory\Allocation\Data\NeedComponent;
use Commerz\Inventory\Allocation\Data\SourceStock;
use Commerz\Inventory\Configuration\InventoryOptions;
use Commerz\Inventory\Items\Data\StockItemId;
use Commerz\Inventory\Locations\Data\LocationId;
use Composer\InstalledVersions;
use CoreX\Context\Contracts\TenancyManager;
use CoreX\Context\Data\AccountRef;
use Filament\Panel;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Doctor\Contracts\DoctorRegistryContract;
use Packman\Doctor\Contracts\HealthCheck;
use Packman\Plugins\Contracts\Contributions\Contributions;
use Packman\Plugins\Contracts\Registry\PluginInspector;
use Packman\Schema\Contracts\Migration\SchemaSets;
use Tenant\Contracts\Runtime\TenantRuntime;

function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function host(array $disabled): Application
{
    $app = require __DIR__.'/bootstrap/app.php';
    $app->afterBootstrapping(LoadConfiguration::class, static function (Application $app) use ($disabled): void {
        $app['config']->set('database.connections', []);
        $app['config']->set('packman.disabled_plugins', $disabled);
    });
    $app->make(Kernel::class)->bootstrap();

    return $app;
}

function capacity(Contributions $contributions): ?int
{
    if (! $contributions->has(AllocationStrategy::KIND, 'priority')) {
        return null;
    }
    $strategy = $contributions->get(AllocationStrategy::KIND, 'priority', AllocationStrategy::class);
    $item = StockItemId::generate();

    return $strategy->capacity(
        new AllocationNeed(SellableRef::of('variant', 'A'), 1, [new NeedComponent($item, 2)]),
        [new SourceStock(LocationId::generate(), 'main', 1, [(string) $item => 8])],
    );
}

function diagnostics(Application $app): array
{
    $registry = $app->make(DoctorRegistryContract::class);
    $keys = array_map(static fn (DoctorCheck $check): string => $check->key(), $registry->all());
    $codes = array_map(static fn (HealthCheck $check): string => $check->code(), $registry->healthChecks());
    check(count($keys) === count(array_unique($keys)), 'Doctor check identities collide');
    check(count($codes) === count(array_unique($codes)), 'Health check identities collide');

    return ['doctor_keys' => $keys, 'health_codes' => $codes];
}

foreach (['corex', 'tenant', 'vaulter-core', 'azguard', 'chatom', 'sockit', 'billium-core', 'commerz', 'proxyma-core'] as $package) {
    check(InstalledVersions::isInstalled('axiomasoft/'.$package), 'Missing real dependency '.$package);
}
check(! class_exists(Panel::class), 'Headless fixture unexpectedly installed Filament');
$a = host([]);
$first = $a->make(Contributions::class);
$firstInspector = $a->make(PluginInspector::class);
$firstDiagnostics = diagnostics($a);
check(capacity($first) === 4, 'Real inventory allocation failed');
$firstSets = array_map(static fn ($set): string => $set->id(), $a->make(SchemaSets::class)->all());
$tenancy = $a->make(TenancyManager::class);
foreach (['demo-a', 'demo-b', 'demo-a'] as $id) {
    $seen = $tenancy->runFor(new AccountRef($id, $id, 'active', [], []), null, static function () use ($tenancy, $a): string {
        $id = $tenancy->context()->account->id;
        check($a->make(TenantRuntime::class)->current()->context->identity->id === $id, 'Corex/Tenant context disagrees');

        return $id;
    });
    check($seen === $id && ! $tenancy->initialized(), 'Tenant context was not restored');
}
$b = host(['commerz.inventory', 'chatom.poll']);
$secondDiagnostics = diagnostics($b);
check(capacity($b->make(Contributions::class)) === null, 'Second host ignored disabled inventory');
check(! $b->bound(InventoryOptions::class), 'Disabled inventory compiled its options');
check(capacity($first) === 4, 'Retained first-host contributions changed');
check($a->make(PluginInspector::class) === $firstInspector, 'First-host inspector changed');
check(diagnostics($a) === $firstDiagnostics, 'Retained first-host diagnostics changed');
check($a->make(TenancyManager::class) === $tenancy && $tenancy->context() === null, 'First-host context leaked');
check($a['db']->getConnections() === [] && $b['db']->getConnections() === [], 'Headless boot opened a database connection');
$secondSets = array_map(static fn ($set): string => $set->id(), $b->make(SchemaSets::class)->all());
check(! in_array('commerz.inventory', array_column($b->make(PluginInspector::class)->describe(), 'id'), true), 'Disabled plugin remains active');

echo json_encode([
    'real_consumers' => 9,
    'simultaneous_hosts' => true,
    'allocation_first' => capacity($first),
    'allocation_second' => null,
    'first_plugin_count' => count($firstInspector->describe()),
    'second_plugin_count' => count($b->make(PluginInspector::class)->describe()),
    'schema_sets_first' => count($firstSets),
    'schema_sets_second' => count($secondSets),
    'tenant_context_restored' => true,
    'diagnostics_first' => $firstDiagnostics,
    'diagnostics_second' => $secondDiagnostics,
    'database_connections' => [],
    'filament_installed' => false,
], JSON_THROW_ON_ERROR).PHP_EOL;
