<?php

declare(strict_types=1);

namespace Packman\Foundation\Internal;

use DateTimeImmutable;
use DateTimeZone;
use Packman\Attributes\Internal;
use Psr\Clock\ClockInterface;

/** Default UTC system clock bound when no custom Psr\Clock\ClockInterface is registered. */
#[Internal]
final readonly class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
