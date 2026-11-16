<?php

declare(strict_types=1);

namespace Packman\Testkit\Fakes\Models;

use Illuminate\Foundation\Auth\User;
use Packman\Attributes\Api;

/**
 * Minimal Laravel Authenticatable user test model with no mass-assignment restrictions.
 *
 * @property string $name
 * @property string $email
 */
#[Api(since: '1.0')]
final class TestUser extends User
{
    /** @var array<string> */
    protected $guarded = [];

    protected $table = 'users';
}
