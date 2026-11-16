<?php

declare(strict_types=1);

namespace Packman\Filament\Tests\Support;

use Illuminate\Database\Eloquent\Model;

class FilamentTestNoPolicyModel extends Model
{
    protected $table = 'products';

    protected $guarded = [];
}
