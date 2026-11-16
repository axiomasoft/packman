<?php

declare(strict_types=1);

namespace Packman\Testkit\Data\Support;

use Packman\Attributes\Api;

/** Value box preserving whether an optional fixture value was explicitly stored. */
#[Api(since: '1.0')]
final readonly class StoredValue
{
    private function __construct(
        public bool $present,
        public mixed $value,
    ) {}

    public static function of(mixed $value): self
    {
        return new self(true, $value);
    }

    public static function absent(): self
    {
        return new self(false, null);
    }
}
