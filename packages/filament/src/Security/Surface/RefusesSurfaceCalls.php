<?php

declare(strict_types=1);

namespace Packman\Filament\Security\Surface;

use Filament\Pages\Page;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Widgets\Widget;
use Packman\Attributes\Internal;
use Packman\Filament\Concerns\Authorization\AuthorizesPage;
use Packman\Filament\Concerns\Authorization\AuthorizesRelationManager;
use Packman\Filament\Concerns\Authorization\AuthorizesWidget;
use Packman\Filament\Internal\Reflection\ClassTraits;
use Packman\Filament\Internal\Surface\FilamentContext;
use Packman\Filament\Security\Authorization\FilamentGate;

/**
 * Refuses direct Livewire method calls against unauthorized relation managers, widgets, and pages.
 */
#[Internal]
final class RefusesSurfaceCalls
{
    public static function refuseRelationManager(RelationManager $component): void
    {
        $context = FilamentContext::serving();

        if ($context?->enforced() !== true) {
            return;
        }

        $owner = FilamentContext::ownerOf($component);

        if (is_array($owner) && $context->excludes('resources', $owner[0])) {
            return;
        }

        abort_unless(
            ClassTraits::keepsMethods($component, AuthorizesRelationManager::class, ['canViewForRecord', 'getAuthorizationResponse', 'makeTable']),
            403,
        );
        abort_unless(FilamentContext::relationResource($component::class) !== null, 403);
        abort_unless($component::canViewForRecord($component->getOwnerRecord(), $component->getPageClass()), 403);
    }

    public function __invoke(object $component): void
    {
        if ($component instanceof RelationManager) {
            self::refuseRelationManager($component);

            return;
        }

        if ((! $component instanceof Widget && ! $component instanceof Page) || FilamentContext::serving()?->enforced() !== true) {
            return;
        }

        if ($component instanceof Widget && ClassTraits::has($component, AuthorizesWidget::class)) {
            abort_unless(FilamentGate::widget($component::class)->allowed(), 403);

            return;
        }

        if ($component instanceof Page && ClassTraits::has($component, AuthorizesPage::class)) {
            abort_unless(FilamentGate::page($component::class)->allowed(), 403);
        }
    }
}
