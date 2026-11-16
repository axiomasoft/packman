<?php

declare(strict_types=1);

namespace Fixture\ModularPlatform\Panels;

use Fixture\ModularPlatform\Filament\ContactUser;
use Packman\Panels\Builder\PanelBuilder;
use Packman\Panels\Builder\PanelProvider;

final class CrmPanelProvider extends PanelProvider
{
    public static function getId(): string
    {
        return 'crm-admin';
    }

    public function panel(PanelBuilder $panel): PanelBuilder
    {
        return $panel
            ->for(ContactUser::class)
            ->label('CRM Admin');
    }
}
