<?php

declare(strict_types=1);

namespace Fixture\CrmModule;

use Fixture\ModularPlatform\Filament\Contact;
use Fixture\ModularPlatform\Panels\CrmPanelProvider;
use Packman\Extensions\Concerns\Composition\ProvidesPanels;
use Packman\Extensions\Concerns\Configuration\Configurable;
use Packman\Extensions\Concerns\Configuration\InteractsWithDatabaseSettings;
use Packman\Extensions\Concerns\Persistence\OwnsSchema;
use Packman\Extensions\Concerns\Persistence\RegistersInDatabase;
use Packman\Extensions\Concerns\Runtime\DeclaresFeatureFlags;
use Packman\Extensions\Contracts\Composition\WithPanels;
use Packman\Extensions\Contracts\Configuration\WithConfiguration;
use Packman\Extensions\Contracts\Configuration\WithDatabaseSettings;
use Packman\Extensions\Contracts\Persistence\WithDatabaseRegistration;
use Packman\Extensions\Contracts\Persistence\WithSchema;
use Packman\Extensions\Contracts\Runtime\WithFeatureFlags;
use Packman\Features\Data\FeatureFlagDefinition;
use Packman\Modules\Contracts\Definition\ModuleDefinition;
use Packman\Modules\Data\Entities\Entity;
use Packman\Modules\Data\Entities\FieldDefinition;
use Packman\Modules\Data\Lifecycle\ModuleManifest;

final class CrmModule extends ModuleDefinition implements WithConfiguration, WithDatabaseRegistration, WithDatabaseSettings, WithFeatureFlags, WithPanels, WithSchema
{
    use Configurable;
    use DeclaresFeatureFlags;
    use InteractsWithDatabaseSettings;
    use OwnsSchema;
    use ProvidesPanels;
    use RegistersInDatabase;

    public function manifest(): ModuleManifest
    {
        return ModuleManifest::make('fixture/crm-module')
            ->title('Fixture CRM')
            ->tablePrefix('crm')
            ->extends([
                Entity::make(Contact::class)
                    ->handle('crm.contact')
                    ->table('crm_contacts')
                    ->label('Contact', 'Contacts')
                    ->fields([
                        new FieldDefinition('name', 'string'),
                    ]),
            ]);
    }

    public function configRoot(): string
    {
        return 'crm';
    }

    public function tablePrefix(): string
    {
        return 'crm';
    }

    public function settingsNamespace(): string
    {
        return 'crm';
    }

    /** @return array<string, mixed> */
    public function defaultSettings(): array
    {
        return [
            'default_stage' => 'lead',
            'webhook_secret' => 'crm-default-secret',
        ];
    }

    /** @return list<string> */
    public function sensitiveSettings(): array
    {
        return ['webhook_secret'];
    }

    /** @return list<FeatureFlagDefinition> */
    public function featureFlags(): array
    {
        return [
            FeatureFlagDefinition::make('crm.contacts', default: true)->module('fixture/crm-module'),
        ];
    }

    /** @return list<class-string> */
    public function panelProviders(): array
    {
        return [CrmPanelProvider::class];
    }
}
