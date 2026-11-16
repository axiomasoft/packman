<?php

declare(strict_types=1);

namespace Packman\Testkit\Concurrency\Process;

use Packman\Attributes\Api;
use Packman\Testkit\Data\Concurrency\RaceOutcome;

/**
 * Repeats an indexed N-worker synchronized process race via Concurrency::run().
 */
#[Api(since: '1.0')]
final class ForkRace
{
    /**
     * @param  callable(int): mixed  $worker
     */
    public static function run(int $workers, callable $worker): RaceOutcome
    {
        $startedAt = hrtime(true);
        $tasks = [];

        for ($i = 0; $i < max(1, $workers); $i++) {
            $tasks[] = static fn (): mixed => $worker($i);
        }

        $outcomes = Concurrency::run($tasks);
        $elapsedMs = (hrtime(true) - $startedAt) / 1_000_000.0;

        $results = [];
        $errors = [];
        foreach ($outcomes as $outcome) {
            if ($outcome->succeeded) {
                $results[] = $outcome->value;
            } else {
                $errors[] = sprintf(
                    'worker#%d (%s): %s',
                    $outcome->index,
                    $outcome->errorClass ?? 'Throwable',
                    $outcome->errorMessage ?? 'unknown error',
                );
            }
        }

        return new RaceOutcome(
            workers: max(1, $workers),
            results: $results,
            errors: $errors,
            elapsedMs: $elapsedMs,
        );
    }
}
