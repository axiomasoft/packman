<?php

declare(strict_types=1);

namespace Packman\Filament\Security\Surface;

use Filament\Pages\Page;
use Filament\Resources\Pages\Page as ResourcePage;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Widgets\Widget;
use Livewire\ComponentHook;
use Packman\Attributes\Internal;
use Packman\Filament\Concerns\Authorization\AuthorizesPage;
use Packman\Filament\Concerns\Authorization\AuthorizesWidget;
use Packman\Filament\Internal\Reflection\ClassTraits;
use Packman\Filament\Internal\Surface\FilamentContext;
use Packman\Filament\Security\Authorization\FilamentGate;

/**
 * Livewire ComponentHook running before component `boot()` so unauthorized surfaces abort prior to executing setup logic.
 */
#[Internal]
final class RefusesSurfaceBoot extends ComponentHook
{
    public function mount(): void
    {
        $this->refuse();
    }

    public function hydrate(): void
    {
        $this->refuse();
    }

    private function refuse(): void
    {
        $component = $this->component;

        if ($component instanceof RelationManager) {
            RefusesSurfaceCalls::refuseRelationManager($component);

            return;
        }

        if ($component instanceof Page && ! $component instanceof ResourcePage) {
            $context = FilamentContext::serving();

            if ($context?->enforced() !== true || $context->excludes('pages', $component::class)) {
                return;
            }

            abort_unless(ClassTraits::keepsMethods($component, AuthorizesPage::class, ['canAccess']), 403);
            abort_unless(FilamentGate::page($component::class)->allowed(), 403);

            return;
        }

        if (! $component instanceof Widget) {
            return;
        }

        $context = FilamentContext::serving();

        if ($context?->enforced() !== true || $context->excludes('widgets', $component::class)) {
            return;
        }

        abort_unless(ClassTraits::has($component, AuthorizesWidget::class), 403);
        abort_unless(FilamentGate::widget($component::class)->allowed(), 403);
    }
}
