<?php

declare(strict_types=1);

namespace Packman\Filament\Concerns\Authorization;

use Filament\Pages\Page;
use Packman\Attributes\Api;
use Packman\Filament\Security\Authorization\FilamentGate;

/**
 * Guards Filament standalone page access and Livewire boot before mount/render executes.
 *
 * @phpstan-require-extends Page
 */
#[Api(since: '1.0')]
trait AuthorizesPage
{
    public static function canAccess(): bool
    {
        return FilamentGate::page(static::class)->allowed();
    }

    public function bootAuthorizesPage(): void
    {
        abort_unless(static::canAccess(), 403);
    }
}
