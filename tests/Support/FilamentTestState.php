<?php

declare(strict_types=1);

namespace Packman\Filament\Tests\Support;

use Filament\Actions\Action;
use Filament\Tables\Columns\Column;

class FilamentTestState
{
    /** @var list<Column> */
    public static array $columns = [];

    /** @var list<Action> */
    public static array $recordActions = [];

    /** @var list<Action> */
    public static array $toolbarActions = [];

    public static int $pageMounts = 0;

    public static function reset(): void
    {
        self::$columns = [];
        self::$recordActions = [];
        self::$toolbarActions = [];
        self::$pageMounts = 0;
    }
}
