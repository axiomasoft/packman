<?php

declare(strict_types=1);

namespace Packman\Filament\Tests\Support;

use Filament\Resources\Pages\ListRecords;

class FilamentTestListOrders extends ListRecords
{
    protected static string $resource = FilamentTestOrderResource::class;
}
