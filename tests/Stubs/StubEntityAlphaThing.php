<?php

declare(strict_types=1);

namespace Packman\Modules\Tests\Stubs;

use Illuminate\Database\Eloquent\Model;

final class StubEntityAlphaThing extends Model
{
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['note' => 'string'];
    }
}
