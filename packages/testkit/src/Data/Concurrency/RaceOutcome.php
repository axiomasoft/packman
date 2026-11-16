<?php

declare(strict_types=1);

namespace Packman\Testkit\Data\Concurrency;

use Packman\Attributes\Api;
use RuntimeException;

/** Aggregated result across all parallel workers started by ForkRace. */
#[Api(since: '1.0')]
final readonly class RaceOutcome
{
    /**
     * @param  list<mixed>  $results
     * @param  list<string>  $errors
     */
    public function __construct(
        public int $workers,
        public array $results,
        public array $errors,
        public float $elapsedMs,
    ) {}

    public function succeededCount(): int
    {
        return count($this->results);
    }

    public function failedCount(): int
    {
        return count($this->errors);
    }

    public function assertAllSucceeded(): self
    {
        if ($this->errors !== []) {
            throw new RuntimeException('ForkRace worker failures: '.implode('; ', $this->errors));
        }

        if (count($this->results) !== $this->workers) {
            throw new RuntimeException(sprintf(
                'ForkRace expected %d results, got %d.',
                $this->workers,
                count($this->results),
            ));
        }

        return $this;
    }

    /** @return list<mixed> */
    public function values(): array
    {
        return $this->results;
    }
}
