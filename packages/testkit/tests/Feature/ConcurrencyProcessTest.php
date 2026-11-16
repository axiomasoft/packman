<?php

declare(strict_types=1);

use Packman\Testkit\Concurrency\Process\Concurrency;
use Packman\Testkit\Concurrency\Process\ForkRace;
use Packman\Testkit\Support\Runtime\TestGroups;

it('executes parallel worker closures cleanly with Concurrency and ForkRace', function (): void {
    if (! TestGroups::hasPcntl()) {
        $this->markTestSkipped('ext-pcntl is required for process concurrency tests.');
    }

    $outcomes = Concurrency::run([
        static fn (): string => 'first',
        static fn (): string => 'second',
        static fn (): never => throw new RuntimeException('intentional worker failure'),
    ]);

    expect($outcomes)->toHaveCount(3)
        ->and($outcomes[0]->succeeded)->toBeTrue()
        ->and($outcomes[0]->value)->toBe('first')
        ->and($outcomes[1]->succeeded)->toBeTrue()
        ->and($outcomes[1]->value)->toBe('second')
        ->and($outcomes[2]->succeeded)->toBeFalse()
        ->and($outcomes[2]->errorClass)->toBe(RuntimeException::class);

    $race = ForkRace::run(4, static fn (int $worker): int => $worker * 10);
    $race->assertAllSucceeded();
    expect($race->values())->toBe([0, 10, 20, 30])
        ->and($race->elapsedMs)->toBeGreaterThan(0.0);
});
