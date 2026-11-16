<?php

declare(strict_types=1);

namespace Fixture\StandaloneLaravel;

use Packman\Extensions\Concerns\Composition\ProvidesCapabilities;
use Packman\Extensions\Concerns\Configuration\Configurable;
use Packman\Extensions\Concerns\Persistence\OwnsSchema;
use Packman\Extensions\Contracts\Composition\WithCapabilities;
use Packman\Extensions\Contracts\Configuration\WithConfiguration;
use Packman\Extensions\Contracts\Persistence\WithSchema;
use Packman\Plugins\Concerns\RegistersNothing;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;

final class BillingPlugin implements ExtensionPlugin, WithCapabilities, WithConfiguration, WithSchema
{
    use Configurable;
    use OwnsSchema;
    use ProvidesCapabilities;
    use RegistersNothing;

    public function manifest(): PluginManifest
    {
        return new PluginManifest(
            id: 'fixture.billing',
            version: '1.0.0',
            databases: ['pgsql', 'sqlite'],
        );
    }

    public function configRoot(): string
    {
        return 'billing';
    }

    public function defaultConfigPath(): string
    {
        return dirname(__DIR__).'/config/billing.php';
    }

    public function optionsClass(): string
    {
        return BillingOptions::class;
    }

    public function tableNouns(): array
    {
        return ['billing_ledger'];
    }

    public function schemaSetProviders(): array
    {
        return [BillingSchemaSetProvider::class];
    }

    public function definedCapabilities(): array
    {
        return [InvoiceFormatter::class => 'required-single'];
    }

    public function providedCapabilities(): array
    {
        return [InvoiceFormatter::class => DefaultInvoiceFormatter::class];
    }
}
