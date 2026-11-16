<?php

declare(strict_types=1);

namespace Packman\Filament\Tests\Support;

use Filament\Widgets\Widget;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Override;
use Packman\Filament\Concerns\Authorization\AuthorizesWidget;

class FilamentTestStatsWidget extends Widget
{
    use AuthorizesWidget;

    #[Override]
    public function render(): View
    {
        return app(Factory::class)->file(__DIR__.'/../Fixtures/views/test-widget.blade.php');
    }
}
