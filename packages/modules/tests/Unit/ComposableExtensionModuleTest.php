<?php

declare(strict_types=1);

use Packman\Context\Contracts\ContextScopeInterface;
use Packman\Extensions\Concerns\Configuration\Configurable;
use Packman\Extensions\Concerns\Configuration\InteractsWithDatabaseSettings;
use Packman\Extensions\Concerns\Persistence\OwnsSchema;
use Packman\Extensions\Concerns\Persistence\RegistersInDatabase;
use Packman\Extensions\Concerns\Runtime\DeclaresFeatureFlags;
use Packman\Extensions\Concerns\Runtime\HasLifecycleHooks;
use Packman\Extensions\Contracts\Configuration\WithConfiguration;
use Packman\Extensions\Contracts\Configuration\WithDatabaseSettings;
use Packman\Extensions\Contracts\Persistence\WithDatabaseRegistration;
use Packman\Extensions\Contracts\Persistence\WithSchema;
use Packman\Extensions\Contracts\Runtime\WithFeatureFlags;
use Packman\Extensions\Contracts\Runtime\WithLifecycleHooks;
use Packman\Features\Data\FeatureFlagDefinition;
use Packman\Modules\Contracts\Definition\ModuleDefinition;
use Packman\Modules\Contracts\Registry\ModuleRegistry;
use Packman\Modules\Data\Compiler\CompiledModule;
use Packman\Modules\Data\Compiler\CompiledRegistry;
use Packman\Modules\Data\Lifecycle\ModuleManifest;
use Packman\Modules\Exceptions\Compiler\CompilationException;
use Packman\Modules\Exceptions\Registry\ModuleNotFound;
use Packman\Modules\Internal\Compiler\RegistryCompiler;
use Packman\Modules\Internal\Discovery\ModuleDiscovery;
use Packman\Modules\Internal\Settings\CompiledSettingDefaultsProvider;
use Packman\Modules\Tests\Stubs\Lifecycle\StubDisableHook;
use Packman\Modules\Tests\Stubs\Lifecycle\StubEnableHook;
use Packman\Modules\Tests\Stubs\Lifecycle\StubPurgeHook;

final class FacetedStubModule extends ModuleDefinition implements WithConfiguration, WithDatabaseRegistration, WithDatabaseSettings, WithFeatureFlags, WithLifecycleHooks, WithSchema
{
    use Configurable;
    use DeclaresFeatureFlags;
    use HasLifecycleHooks;
    use InteractsWithDatabaseSettings;
    use OwnsSchema;
    use RegistersInDatabase;

    public function manifest(): ModuleManifest
    {
        return ModuleManifest::make('acme/faceted-crm')
            ->title('Faceted CRM');
    }

    public function configRoot(): string
    {
        return 'faceted-crm';
    }

    public function tablePrefix(): string
    {
        return 'fcr';
    }

    public function settingsNamespace(): string
    {
        return 'fcr';
    }

    public function defaultSettings(): array
    {
        return [
            'api_key' => 'secret-default',
            'page_size' => 25,
        ];
    }

    public function sensitiveSettings(): array
    {
        return ['api_key'];
    }

    public function featureFlags(): array
    {
        return [
            FeatureFlagDefinition::make('fcr.pipelines', default: true)->module('acme/faceted-crm'),
        ];
    }

    public function onEnableHook(): string
    {
        return StubEnableHook::class;
    }

    public function onDisableHook(): string
    {
        return StubDisableHook::class;
    }

    public function onPurgeHook(): string
    {
        return StubPurgeHook::class;
    }
}

it('hydrates composable With* extension traits onto ModuleManifest and compiles them cleanly', function (): void {
    $discovery = new ModuleDiscovery(fallbackModules: [FacetedStubModule::class]);
    $manifests = $discovery->discover();

    expect($manifests)->toHaveCount(1);
    $manifest = $manifests[0];

    expect($manifest->tablePrefix)->toBe('fcr')
        ->and($manifest->settings)->toHaveCount(2)
        ->and($manifest->settings[0]->key)->toBe('api_key')
        ->and($manifest->settings[0]->sensitive)->toBeTrue()
        ->and($manifest->settings[1]->key)->toBe('page_size')
        ->and($manifest->settings[1]->sensitive)->toBeFalse()
        ->and($manifest->featureFlags)->toHaveCount(1)
        ->and($manifest->featureFlags[0]->key)->toBe('fcr.pipelines')
        ->and($manifest->onEnableHook)->toBe(StubEnableHook::class)
        ->and($manifest->onDisableHook)->toBe(StubDisableHook::class)
        ->and($manifest->onPurgeHook)->toBe(StubPurgeHook::class);

    $compiled = (new RegistryCompiler)->compile($manifests, []);
    expect($compiled->settings)->toHaveCount(2)
        ->and($compiled->settings[0]->namespace)->toBe('fcr')
        ->and($compiled->settings[0]->definition->sensitive)->toBeTrue()
        ->and($compiled->featureFlags)->toHaveCount(1);
});

it('discovers modules across configurable packageTypes and extraKeys in installed.json', function (): void {
    $installedJson = tempnam(sys_get_temp_dir(), 'packman-custom-discovery-');
    expect($installedJson)->toBeString();

    file_put_contents((string) $installedJson, json_encode([
        'packages' => [
            [
                'name' => 'vendor/plugin-style',
                'type' => 'custom-plugin',
                'extra' => [
                    'vendor' => [
                        'entry' => FacetedStubModule::class,
                    ],
                ],
            ],
            [
                'name' => 'vendor/ignored-type',
                'type' => 'library',
                'extra' => [
                    'vendor' => [
                        'entry' => FacetedStubModule::class,
                    ],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    try {
        $discovery = new ModuleDiscovery(
            installedJsonPath: (string) $installedJson,
            packageTypes: ['custom-plugin'],
            extraKeys: [['vendor', 'entry']],
        );

        $manifests = $discovery->discover();
        expect($manifests)->toHaveCount(1)
            ->and($manifests[0]->composerName)->toBe('acme/faceted-crm');
    } finally {
        @unlink((string) $installedJson);
    }
});

final class CustomNamespaceSettingsModule extends ModuleDefinition implements WithDatabaseSettings
{
    use InteractsWithDatabaseSettings;

    public function manifest(): ModuleManifest
    {
        return ModuleManifest::make('acme/custom-settings')
            ->title('Custom Settings')
            ->tablePrefix('cst');
    }

    public function settingsNamespace(): string
    {
        return 'custom-settings';
    }

    public function defaultSettings(): array
    {
        return [
            'endpoint' => 'https://api.example.test',
            'api_secret' => 's3cr3t',
        ];
    }

    public function sensitiveSettings(): array
    {
        return ['api_secret'];
    }
}

final class EmptyNamespaceSettingsModule extends ModuleDefinition implements WithDatabaseSettings
{
    use InteractsWithDatabaseSettings;

    public function manifest(): ModuleManifest
    {
        return ModuleManifest::make('acme/empty-ns-settings')
            ->title('Empty Namespace Settings')
            ->tablePrefix('ens');
    }

    public function settingsNamespace(): string
    {
        return '';
    }

    public function defaultSettings(): array
    {
        return ['mode' => 'safe'];
    }
}

final class CollidingNamespaceSettingsModule extends ModuleDefinition implements WithDatabaseSettings
{
    use InteractsWithDatabaseSettings;

    public function manifest(): ModuleManifest
    {
        return ModuleManifest::make('acme/colliding-settings')
            ->title('Colliding Settings')
            ->tablePrefix('col');
    }

    public function settingsNamespace(): string
    {
        return 'custom-settings';
    }

    public function defaultSettings(): array
    {
        return ['endpoint' => 'https://conflict.example.test'];
    }
}

it('preserves non-empty WithDatabaseSettings::settingsNamespace() and falls back to tablePrefix when empty', function (): void {
    $manifests = (new ModuleDiscovery(fallbackModules: [
        CustomNamespaceSettingsModule::class,
        EmptyNamespaceSettingsModule::class,
    ]))->discover();

    expect($manifests[0]->settingsNamespace)->toBe('custom-settings')
        ->and($manifests[1]->settingsNamespace)->toBeNull();

    $compiled = (new RegistryCompiler)->compile($manifests, []);

    expect($compiled->settings)->toHaveCount(3)
        ->and($compiled->settings[0]->namespace)->toBe('custom-settings')
        ->and($compiled->settings[0]->definition->key)->toBe('endpoint')
        ->and($compiled->settings[0]->definition->default)->toBe('https://api.example.test')
        ->and($compiled->settings[1]->namespace)->toBe('custom-settings')
        ->and($compiled->settings[1]->definition->key)->toBe('api_secret')
        ->and($compiled->settings[1]->definition->sensitive)->toBeTrue()
        ->and($compiled->settings[2]->namespace)->toBe('ens')
        ->and($compiled->settings[2]->definition->key)->toBe('mode');

    $registryStub = new readonly class($compiled) implements ModuleRegistry
    {
        public function __construct(private CompiledRegistry $compiled) {}

        public function all(): array
        {
            return [];
        }

        public function get(string $name): CompiledModule
        {
            throw ModuleNotFound::forName($name);
        }

        public function active(ContextScopeInterface $ctx): array
        {
            return [];
        }

        public function isActive(string $name, ContextScopeInterface $ctx): bool
        {
            return false;
        }

        public function compiled(): CompiledRegistry
        {
            return $this->compiled;
        }
    };

    $provider = new CompiledSettingDefaultsProvider($registryStub);
    expect($provider->defaultFor('custom-settings', 'endpoint'))->toBe('https://api.example.test')
        ->and($provider->isSensitive('custom-settings', 'api_secret'))->toBeTrue()
        ->and($provider->defaultFor('ens', 'mode'))->toBe('safe');

    $colliding = (new ModuleDiscovery(fallbackModules: [
        CustomNamespaceSettingsModule::class,
        CollidingNamespaceSettingsModule::class,
    ]))->discover();

    expect(fn () => (new RegistryCompiler)->compile($colliding, []))
        ->toThrow(CompilationException::class, 'custom-settings:endpoint');
});
