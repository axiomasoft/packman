<?php

declare(strict_types=1);

namespace Fixture\ModularPlatform\Filament;

use Illuminate\Database\Eloquent\Model;

final class Contact extends Model
{
    protected $table = 'crm_contacts';

    protected $guarded = [];
}
