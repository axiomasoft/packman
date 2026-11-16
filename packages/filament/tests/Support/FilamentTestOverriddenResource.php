<?php

declare(strict_types=1);

namespace Packman\Filament\Tests\Support;

use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Override;
use Packman\Filament\Concerns\Authorization\AuthorizesResource;

class FilamentTestOverriddenResource extends Resource
{
    use AuthorizesResource;

    protected static ?string $model = FilamentTestProduct::class;

    protected static ?string $slug = 'overridden';

    /**
     * @return Builder<Model>
     */
    #[Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery();
    }
}
