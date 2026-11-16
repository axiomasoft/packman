<?php

declare(strict_types=1);

namespace Packman\Filament\Tests\Support;

use Filament\Pages\Page;
use Packman\Filament\Concerns\Authorization\AuthorizesPage;

class FilamentTestReportsPage extends Page
{
    use AuthorizesPage;

    protected string $view = 'test-page';

    protected static ?string $slug = 'reports';

    public function mount(): void
    {
        FilamentTestState::$pageMounts++;
    }
}
