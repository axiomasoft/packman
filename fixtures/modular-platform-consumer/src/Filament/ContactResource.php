<?php

declare(strict_types=1);

namespace Fixture\ModularPlatform\Filament;

use Filament\Resources\Resource;
use Packman\Filament\Concerns\Authorization\AuthorizesResource;

final class ContactResource extends Resource
{
    use AuthorizesResource;

    protected static ?string $model = Contact::class;

    protected static ?string $slug = 'contacts';

    public static function getPages(): array
    {
        return [];
    }
}
