<?php

declare(strict_types=1);

namespace Packman\Testkit\Exceptions\Concurrency;

use Packman\Attributes\Api;
use RuntimeException;

/** Thrown when child workers fail to synchronize on a Postgres or process barrier within timeout. */
#[Api(since: '1.0')]
final class BarrierTimeoutException extends RuntimeException
{
    public static function afterSeconds(string $barrier, float $seconds, int $arrived, int $expected): self
    {
        return new self(sprintf(
            'Barrier "%s" timed out after %.2fs (%d/%d participants arrived).',
            $barrier,
            $seconds,
            $arrived,
            $expected,
        ));
    }
}
