<?php

declare(strict_types=1);

namespace Packman\Core\Tests\Unit\Extensions;

use Packman\Extensions\Concerns\Configurable;
use Packman\Extensions\Concerns\ContributesToSlots;
use Packman\Extensions\Concerns\DeclaresFeatureFlags;
use Packman\Extensions\Concerns\HasLifecycleHooks;
use Packman\Extensions\Concerns\InteractsWithDatabaseSettings;
use Packman\Extensions\Concerns\OwnsSchema;
use Packman\Extensions\Concerns\ProvidesCapabilities;
use Packman\Extensions\Concerns\ProvidesDiagnostics;
use Packman\Extensions\Concerns\ProvidesPanels;
use Packman\Extensions\Concerns\RegistersInDatabase;
use Packman\Extensions\Contracts\WithCapabilities;
use Packman\Extensions\Contracts\WithConfiguration;
use Packman\Extensions\Contracts\WithDatabaseRegistration;
use Packman\Extensions\Contracts\WithDatabaseSettings;
use Packman\Extensions\Contracts\WithDiagnostics;
use Packman\Extensions\Contracts\WithFeatureFlags;
use Packman\Extensions\Contracts\WithLifecycleHooks;
use Packman\Extensions\Contracts\WithPanels;
use Packman\Extensions\Contracts\WithSchema;
use Packman\Extensions\Contracts\WithSlots;
use Packman\Extensions\ExtensionInspector;
use Packman\Features\Data\FeatureFlagDefinition;
use PHPUnit\Framework\TestCase;
use stdClass;

final class ExtensionInspectorTest extends TestCase
{
    public function test_inspects_plain_object_without_facets(): void
    {
        $profile = ExtensionInspector::inspect(new stdClass);

        self::assertFalse($profile->hasConfiguration);
        self::assertNull($profile->configRoot);
        self::assertFalse($profile->hasDatabaseSettings);
        self::assertFalse($profile->persistsInDatabase);
        self::assertFalse($profile->tracksOwnedRecords);
        self::assertSame('standard', $profile->defaultEdition);
        self::assertFalse($profile->hasSchema);
        self::assertSame([], $profile->tableNouns);
        self::assertSame([], $profile->definedCapabilities);
        self::assertSame([], $profile->slotContributions);
        self::assertNull($profile->onEnableHook);
        self::assertSame([], $profile->healthChecks);
        self::assertSame([], $profile->panelProviders);
        self::assertSame([], $profile->featureFlags);
    }

    public function test_inspects_composable_extension_facets_and_trait_defaults(): void
    {
        $extension = new class implements WithCapabilities, WithConfiguration, WithDatabaseRegistration, WithDatabaseSettings, WithDiagnostics, WithFeatureFlags, WithLifecycleHooks, WithPanels, WithSchema, WithSlots
        {
            use Configurable;
            use ContributesToSlots;
            use DeclaresFeatureFlags;
            use HasLifecycleHooks;
            use InteractsWithDatabaseSettings;
            use OwnsSchema;
            use ProvidesCapabilities;
            use ProvidesDiagnostics;
            use ProvidesPanels;
            use RegistersInDatabase;

            public function configRoot(): string
            {
                return 'acme.billing';
            }

            public function settingsNamespace(): string
            {
                return 'billing';
            }

            /** @return array<string, mixed> */
            public function defaultSettings(): array
            {
                return ['currency' => 'USD'];
            }

            /** @return list<string> */
            public function sensitiveSettings(): array
            {
                return ['api_secret'];
            }

            /** @return list<string> */
            public function tableNouns(): array
            {
                return ['invoice', 'payment'];
            }

            /** @return list<FeatureFlagDefinition> */
            public function featureFlags(): array
            {
                return [FeatureFlagDefinition::make('billing.v2', true)];
            }
        };

        $profile = ExtensionInspector::inspect($extension);

        self::assertTrue($profile->hasConfiguration);
        self::assertSame('acme.billing', $profile->configRoot);
        self::assertNull($profile->defaultConfigPath);
        self::assertNull($profile->optionsClass);
        self::assertNull($profile->configSectionClass);

        self::assertTrue($profile->hasDatabaseSettings);
        self::assertSame('billing', $profile->settingsNamespace);
        self::assertSame(['currency' => 'USD'], $profile->defaultSettings);
        self::assertSame(['api_secret'], $profile->sensitiveSettings);

        self::assertTrue($profile->persistsInDatabase);
        self::assertTrue($profile->tracksOwnedRecords);
        self::assertSame('standard', $profile->defaultEdition);

        self::assertTrue($profile->hasSchema);
        self::assertNull($profile->tablePrefix);
        self::assertSame(['invoice', 'payment'], $profile->tableNouns);
        self::assertNull($profile->migrationPath);
        self::assertSame([], $profile->schemaSetProviders);
        self::assertSame([], $profile->schemaComponents);

        self::assertSame([], $profile->definedCapabilities);
        self::assertSame([], $profile->providedCapabilities);
        self::assertSame([], $profile->definedSlots);
        self::assertSame([], $profile->slotContributions);
        self::assertNull($profile->onEnableHook);
        self::assertNull($profile->onDisableHook);
        self::assertNull($profile->onPurgeHook);
        self::assertNull($profile->removalGuard);
        self::assertSame([], $profile->healthChecks);
        self::assertSame([], $profile->problemProviders);
        self::assertSame([], $profile->panelProviders);
        self::assertCount(1, $profile->featureFlags);
        self::assertSame('billing.v2', $profile->featureFlags[0]->key);
    }

    public function test_inspects_custom_facet_values(): void
    {
        $extension = new class implements WithCapabilities, WithDatabaseRegistration, WithLifecycleHooks, WithPanels, WithSlots
        {
            use ContributesToSlots;
            use HasLifecycleHooks;
            use ProvidesCapabilities;
            use ProvidesPanels;
            use RegistersInDatabase;

            public function defaultEdition(): string
            {
                return 'enterprise';
            }

            /** @return array<class-string, string> */
            public function definedCapabilities(): array
            {
                return [stdClass::class => 'required-single'];
            }

            /** @return array<class-string, class-string> */
            public function providedCapabilities(): array
            {
                return [stdClass::class => stdClass::class];
            }

            /** @return array<class-string, array{kind: string, cardinality?: string}> */
            public function definedSlots(): array
            {
                return [stdClass::class => ['kind' => 'handler', 'cardinality' => 'many']];
            }

            /** @return list<array{slot: class-string, id: string, implementation: class-string, priority?: int, key?: string|null}> */
            public function slotContributions(): array
            {
                return [[
                    'slot' => stdClass::class,
                    'id' => 'test.slot',
                    'implementation' => stdClass::class,
                    'priority' => 5,
                    'key' => 'sample',
                ]];
            }

            /** @return class-string */
            public function removalGuard(): string
            {
                return stdClass::class;
            }

            /** @return list<class-string> */
            public function panelProviders(): array
            {
                return [stdClass::class];
            }
        };

        $profile = ExtensionInspector::inspect($extension);

        self::assertSame('enterprise', $profile->defaultEdition);
        self::assertSame([stdClass::class => 'required-single'], $profile->definedCapabilities);
        self::assertSame([stdClass::class => stdClass::class], $profile->providedCapabilities);
        self::assertSame('handler', $profile->definedSlots[stdClass::class]['kind']);
        self::assertCount(1, $profile->slotContributions);
        self::assertSame(stdClass::class, $profile->removalGuard);
        self::assertSame([stdClass::class], $profile->panelProviders);
    }
}
