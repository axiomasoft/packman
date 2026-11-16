<?php

declare(strict_types=1);

namespace Packman\Filament\Tests\Support;

use Illuminate\Foundation\Auth\User as Authenticatable;

class FilamentTestUser extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];
}
