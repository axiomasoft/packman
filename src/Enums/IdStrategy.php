<?php

declare(strict_types=1);

namespace Packman\Schema\Enums;

use Packman\Attributes\Api;

/**
 * Key type of a space's own rows. A closed set: the column shape of each case is frozen in SpaceSchema, so a
 * released migration always produces the same DDL. Chosen per space and immutable after its first migrate.
 */
#[Api(since: '1.0')]
enum IdStrategy: string
{
    case Uuid7 = 'uuid7';
    case Ulid = 'ulid';
    case Bigint = 'bigint';

    /** Eloquent key type of a model on a space with this strategy. */
    public function keyType(): string
    {
        return $this === self::Bigint ? 'int' : 'string';
    }

    public function incrementing(): bool
    {
        return $this === self::Bigint;
    }
}
