<?php

declare(strict_types=1);

namespace Packman\Modules\Tests\Stubs;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Minimal Eloquent test double for entity registry and morph map tests.
 *
 * @property string $id
 * @property string $name
 */
final class Widget extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $guarded = [];
}
