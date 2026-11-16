<?php

declare(strict_types=1);

namespace Packman\Filament\Tests\Support;

use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Override;
use Packman\Filament\Concerns\Authorization\AuthorizesResource;

class FilamentTestOrderResource extends Resource
{
    use AuthorizesResource;

    protected static ?string $model = FilamentTestOrder::class;

    protected static ?string $slug = 'orders';

    #[Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('number')->required(),
        ]);
    }

    #[Override]
    public static function table(Table $table): Table
    {
        $columns = FilamentTestState::$columns !== []
            ? FilamentTestState::$columns
            : [TextColumn::make('number')];

        $recordActions = FilamentTestState::$recordActions !== []
            ? FilamentTestState::$recordActions
            : [DeleteAction::make()];

        $toolbarActions = FilamentTestState::$toolbarActions !== []
            ? FilamentTestState::$toolbarActions
            : [DeleteBulkAction::make()];

        return $table
            ->columns($columns)
            ->recordActions($recordActions)
            ->toolbarActions($toolbarActions);
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    protected static function modifyEloquentQuery(Builder $query): Builder
    {
        $query->getQuery()->where('secret', false);

        return $query;
    }

    #[Override]
    public static function getRelations(): array
    {
        return [
            FilamentTestProductsRelationManager::class,
        ];
    }

    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => FilamentTestListOrders::route('/'),
        ];
    }
}
