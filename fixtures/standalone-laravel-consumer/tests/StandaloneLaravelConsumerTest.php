<?php

declare(strict_types=1);

namespace Fixture\StandaloneLaravel\Tests;

use Fixture\StandaloneLaravel\BillingOptions;
use Fixture\StandaloneLaravel\BillingPlugin;
use Fixture\StandaloneLaravel\BillingReceiptPlugin;
use Fixture\StandaloneLaravel\BillingSchemaSetProvider;
use Fixture\StandaloneLaravel\InvoiceFormatter;
use Illuminate\Support\Facades\Schema;
use Packman\PackmanServiceProvider;
use Packman\Plugins\Contracts\Contributions\Capabilities;
use Packman\Plugins\Contracts\Contributions\Contributions;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Contracts\Registry\PluginInspector;
use Packman\Plugins\Exceptions\Registry\RegistryFrozenException;
use Packman\Plugins\Internal\Contributions\ExtensionRegistry;
use Packman\Plugins\Internal\Graph\PluginCatalog;
use Packman\Plugins\Laravel\PluginsServiceProvider;
use Packman\Schema\Contracts\Compiler\SchemaOwnership;
use Packman\Schema\Contracts\Migration\SchemaMigrator;
use Packman\Schema\Contracts\Migration\SchemaSetProvider;
use Packman\Schema\Enums\SchemaScope;
use Packman\Schema\Laravel\SchemaServiceProvider;
use Packman\Testkit\Concerns\Conformance\PluginContractTests;
use Packman\Testkit\PackageTestCase;

final class StandaloneLaravelConsumerTest extends PackageTestCase
{
    use PluginContractTests;

    protected function pluginUnderTest(): ExtensionPlugin
    {
        return new BillingPlugin;
    }

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [
            PackmanServiceProvider::class,
            SchemaServiceProvider::class,
            PluginsServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('packman.plugins', [
            BillingReceiptPlugin::class,
            BillingPlugin::class,
        ]);
        $app['config']->set('billing.currency', 'EUR');

        $app->tag([BillingSchemaSetProvider::class], SchemaSetProvider::class);
    }

    public function test_zero_packman_modules_installed_in_lock_or_vendor(): void
    {
        $root = dirname(__DIR__);
        $lock = json_decode((string) file_get_contents($root.'/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($lock);

        foreach (['packages', 'packages-dev'] as $section) {
            foreach ($lock[$section] ?? [] as $pkg) {
                self::assertIsArray($pkg);
                self::assertNotSame('axiomasoft/packman-modules', $pkg['name'] ?? '');
            }
        }

        self::assertFalse(class_exists('Packman\\Modules\\ModulesServiceProvider'));
    }

    public function test_stateless_plugin_compiles_freezes_catalog_and_runs_schema_migrations_without_mod_tables(): void
    {
        $contributions = $this->app->make(Contributions::class);
        $inspector = $this->app->make(PluginInspector::class);
        $catalog = $this->app->make(PluginCatalog::class);
        $capabilities = $this->app->make(Capabilities::class);
        $extensions = $this->app->make(ExtensionRegistry::class);

        $ids = array_map(static fn ($d): string => $d->id, $inspector->describe());
        self::assertSame(['fixture.billing', 'fixture.billing-receipts'], $ids);

        // Sealed PluginCatalog refuses late builtIn registration
        $caught = false;
        try {
            $catalog->builtIn(BillingPlugin::class);
        } catch (RegistryFrozenException) {
            $caught = true;
        }
        self::assertTrue($caught);

        // WithConfiguration + Configurable hydrated BillingOptions with config override
        $options = $this->app->make(BillingOptions::class);
        self::assertSame('EUR', $options->currency);
        self::assertTrue($options->strictRounding);

        // WithCapabilities + Slot handler
        $formatter = $capabilities->require(InvoiceFormatter::class);
        self::assertSame('EUR 12.50', $formatter->format(1250, $options->currency));
        self::assertInstanceOf(InvoiceFormatter::class, $extensions->handler(InvoiceFormatter::class, 'receipt'));

        // WithSchema registered noun ownership + SchemaMigrator ran tenant SchemaSet with zero mod_* tables
        $ownership = $this->app->make(SchemaOwnership::class);
        self::assertSame('fixture.billing', $ownership->ownerOf('billing_ledgers'));

        $ran = $this->app->make(SchemaMigrator::class)->migrate(SchemaScope::Tenant);
        self::assertSame(['2026_11_16_000001_create_billing_ledgers_table'], $ran);
        self::assertTrue(Schema::hasTable('pmn_billing_ledgers'));
        self::assertFalse(Schema::hasTable('mod_modules'));
        self::assertFalse(Schema::hasTable('mod_records'));
        self::assertFalse(Schema::hasTable('mod_lifecycle_log'));
        self::assertSame([], $contributions->databaseRegisteredPluginIds());
    }
}
