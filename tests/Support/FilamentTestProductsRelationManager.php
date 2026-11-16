<?php

declare(strict_types=1);

namespace Packman\Filament\Tests\Support;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Packman\Filament\Concerns\Authorization\AuthorizesRelationManager;

class FilamentTestProductsRelationManager extends RelationManager
{
    use AuthorizesRelationManager;

    protected static string $relationship = 'products';

    protected static ?string $packmanResource = FilamentTestOrderResource::class;

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name'),
        ]);
    }
}
