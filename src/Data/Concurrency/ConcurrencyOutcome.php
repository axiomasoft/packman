<?php

declare(strict_types=1);

namespace Packman\Testkit\Data\Concurrency;

use Packman\Attributes\Api;

/** Captures the outcome of one parallel child process launched by Concurrency. */
#[Api(since: '1.0')]
final readonly class ConcurrencyOutcome
{
    public function __construct(
        public int $index,
        public int $pid,
        public bool $succeeded,
        public mixed $value = null,
        public ?string $errorClass = null,
        public ?string $errorMessage = null,
    ) {}
}
