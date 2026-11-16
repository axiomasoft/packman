<?php

declare(strict_types=1);

namespace Fixture\StandaloneLaravel;

use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Enums\Contributions\Cardinality;
use Packman\Plugins\Enums\Contributions\SlotKind;
use Packman\Plugins\Laravel\PluginRegistrar;

final class BillingReceiptPlugin implements ExtensionPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest(
            id: 'fixture.billing-receipts',
            version: '1.0.0',
            requires: ['fixture.billing'],
        );
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->defineSlot(InvoiceFormatter::class, SlotKind::Handler, Cardinality::Many);
        $registrar->contributeSlot(
            slot: InvoiceFormatter::class,
            id: 'fixture.default-receipt',
            implementation: DefaultInvoiceFormatter::class,
            priority: 10,
            key: 'receipt',
        );
    }
}
