<?php

declare(strict_types=1);

namespace Packman\Filament\Tests\Support;

use Filament\Resources\Resource;
use Override;

class FilamentTestUnprotectedResource extends Resource
{
    protected static ?string $model = FilamentTestProduct::class;

    protected static ?string $slug = 'unprotected';

    #[Override]
    public static function getPages(): array
    {
        return [];
    }
}
