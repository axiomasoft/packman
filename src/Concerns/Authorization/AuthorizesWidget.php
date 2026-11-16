<?php

declare(strict_types=1);

namespace Packman\Filament\Concerns\Authorization;

use Filament\Widgets\Widget;
use Packman\Attributes\Api;
use Packman\Filament\Security\Authorization\FilamentGate;

/**
 * Guards Filament widget visibility through `FilamentGate`.
 *
 * @phpstan-require-extends Widget
 */
#[Api(since: '1.0')]
trait AuthorizesWidget
{
    public static function canView(): bool
    {
        return FilamentGate::widget(static::class)->allowed();
    }
}
