<?php

declare(strict_types=1);

namespace Packman\Extensions\Data;

use Packman\Attributes\Api;
use Packman\Config\Contracts\ConfigSection;
use Packman\Config\Contracts\OptionsFactory;
use Packman\Features\Data\FeatureFlagDefinition;

/**
 * Normalized snapshot of all composable `With*` capabilities implemented by a plugin or module.
 */
#[Api(since: '1.0')]
final readonly class ExtensionFacetProfile
{
    /**
     * @param  class-string<OptionsFactory>|null  $optionsClass
     * @param  class-string<ConfigSection>|null  $configSectionClass
     * @param  array<string, mixed>  $defaultSettings
     * @param  list<string>  $sensitiveSettings
     * @param  list<string>  $tableNouns
     * @param  list<class-string>  $schemaSetProviders
     * @param  list<class-string>  $schemaComponents
     * @param  array<class-string, string>  $definedCapabilities
     * @param  array<class-string, class-string|array{config: string, default?: class-string|null}>  $providedCapabilities
     * @param  array<class-string, array{kind: string, cardinality?: string, shared?: bool}>  $definedSlots
     * @param  list<array{slot: class-string, id: string, implementation: class-string, priority?: int, key?: string|null}>  $slotContributions
     * @param  class-string|null  $onEnableHook
     * @param  class-string|null  $onDisableHook
     * @param  class-string|null  $onPurgeHook
     * @param  class-string|null  $removalGuard
     * @param  list<class-string>  $healthChecks
     * @param  list<class-string>  $problemProviders
     * @param  list<class-string>  $panelProviders
     * @param  list<FeatureFlagDefinition>  $featureFlags
     */
    public function __construct(
        public bool $hasConfiguration = false,
        public ?string $configRoot = null,
        public ?string $defaultConfigPath = null,
        public ?string $optionsClass = null,
        public ?string $configSectionClass = null,
        public bool $hasDatabaseSettings = false,
        public ?string $settingsNamespace = null,
        public array $defaultSettings = [],
        public array $sensitiveSettings = [],
        public bool $persistsInDatabase = false,
        public bool $tracksOwnedRecords = false,
        public string $defaultEdition = 'standard',
        public bool $hasSchema = false,
        public ?string $tablePrefix = null,
        public array $tableNouns = [],
        public ?string $migrationPath = null,
        public array $schemaSetProviders = [],
        public array $schemaComponents = [],
        public array $definedCapabilities = [],
        public array $providedCapabilities = [],
        public array $definedSlots = [],
        public array $slotContributions = [],
        public ?string $onEnableHook = null,
        public ?string $onDisableHook = null,
        public ?string $onPurgeHook = null,
        public ?string $removalGuard = null,
        public array $healthChecks = [],
        public array $problemProviders = [],
        public array $panelProviders = [],
        public array $featureFlags = [],
    ) {}
}
