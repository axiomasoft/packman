<?php

declare(strict_types=1);

namespace Fixture\ModularPlatform\Filament;

use Illuminate\Foundation\Auth\User as Authenticatable;

final class ContactUser extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];
}
