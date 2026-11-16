<?php

declare(strict_types=1);

namespace Packman\Extensions;

use Packman\Attributes\Api;
use Packman\Extensions\Contracts\Composition\WithCapabilities;
use Packman\Extensions\Contracts\Composition\WithPanels;
use Packman\Extensions\Contracts\Composition\WithSlots;
use Packman\Extensions\Contracts\Configuration\WithConfiguration;
use Packman\Extensions\Contracts\Configuration\WithDatabaseSettings;
use Packman\Extensions\Contracts\Persistence\WithDatabaseRegistration;
use Packman\Extensions\Contracts\Persistence\WithSchema;
use Packman\Extensions\Contracts\Runtime\WithDiagnostics;
use Packman\Extensions\Contracts\Runtime\WithFeatureFlags;
use Packman\Extensions\Contracts\Runtime\WithLifecycleHooks;
use Packman\Extensions\Data\ExtensionFacetProfile;

/**
 * Single canonical inspector extracting composable `With*` facets from any plugin or module instance.
 */
#[Api(since: '1.0')]
final class ExtensionInspector
{
    public static function inspect(object $extension): ExtensionFacetProfile
    {
        return new ExtensionFacetProfile(
            hasConfiguration: $extension instanceof WithConfiguration,
            configRoot: $extension instanceof WithConfiguration ? $extension->configRoot() : null,
            defaultConfigPath: $extension instanceof WithConfiguration ? $extension->defaultConfigPath() : null,
            optionsClass: $extension instanceof WithConfiguration ? $extension->optionsClass() : null,
            configSectionClass: $extension instanceof WithConfiguration ? $extension->configSectionClass() : null,
            hasDatabaseSettings: $extension instanceof WithDatabaseSettings,
            settingsNamespace: $extension instanceof WithDatabaseSettings ? $extension->settingsNamespace() : null,
            defaultSettings: $extension instanceof WithDatabaseSettings ? $extension->defaultSettings() : [],
            sensitiveSettings: $extension instanceof WithDatabaseSettings ? $extension->sensitiveSettings() : [],
            persistsInDatabase: $extension instanceof WithDatabaseRegistration && $extension->persistsStateInDatabase(),
            tracksOwnedRecords: $extension instanceof WithDatabaseRegistration && $extension->tracksOwnedRecords(),
            defaultEdition: $extension instanceof WithDatabaseRegistration ? $extension->defaultEdition() : 'standard',
            hasSchema: $extension instanceof WithSchema,
            tablePrefix: $extension instanceof WithSchema ? $extension->tablePrefix() : null,
            tableNouns: $extension instanceof WithSchema ? $extension->tableNouns() : [],
            migrationPath: $extension instanceof WithSchema ? $extension->migrationPath() : null,
            schemaSetProviders: $extension instanceof WithSchema ? $extension->schemaSetProviders() : [],
            schemaComponents: $extension instanceof WithSchema ? $extension->schemaComponents() : [],
            definedCapabilities: $extension instanceof WithCapabilities ? $extension->definedCapabilities() : [],
            providedCapabilities: $extension instanceof WithCapabilities ? $extension->providedCapabilities() : [],
            definedSlots: $extension instanceof WithSlots ? $extension->definedSlots() : [],
            slotContributions: $extension instanceof WithSlots ? $extension->slotContributions() : [],
            onEnableHook: $extension instanceof WithLifecycleHooks ? $extension->onEnableHook() : null,
            onDisableHook: $extension instanceof WithLifecycleHooks ? $extension->onDisableHook() : null,
            onPurgeHook: $extension instanceof WithLifecycleHooks ? $extension->onPurgeHook() : null,
            removalGuard: $extension instanceof WithLifecycleHooks ? $extension->removalGuard() : null,
            healthChecks: $extension instanceof WithDiagnostics ? $extension->healthChecks() : [],
            problemProviders: $extension instanceof WithDiagnostics ? $extension->problemProviders() : [],
            panelProviders: $extension instanceof WithPanels ? $extension->panelProviders() : [],
            featureFlags: $extension instanceof WithFeatureFlags ? $extension->featureFlags() : [],
        );
    }
}
