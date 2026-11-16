<?php

declare(strict_types=1);

use Packman\Extensions\Concerns\Configurable;
use Packman\Extensions\Concerns\DeclaresFeatureFlags;
use Packman\Extensions\Concerns\HasLifecycleHooks;
use Packman\Extensions\Concerns\InteractsWithDatabaseSettings;
use Packman\Extensions\Concerns\OwnsSchema;
use Packman\Extensions\Concerns\RegistersInDatabase;
use Packman\Extensions\Contracts\WithConfiguration;
use Packman\Extensions\Contracts\WithDatabaseRegistration;
use Packman\Extensions\Contracts\WithDatabaseSettings;
use Packman\Extensions\Contracts\WithFeatureFlags;
use Packman\Extensions\Contracts\WithLifecycleHooks;
use Packman\Extensions\Contracts\WithSchema;
use Packman\Features\Data\FeatureFlagDefinition;
use Packman\Modules\Contracts\ModuleDefinition;
use Packman\Modules\Data\ModuleManifest;
use Packman\Modules\Internal\Compiler\RegistryCompiler;
use Packman\Modules\Internal\Discovery\ModuleDiscovery;
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
