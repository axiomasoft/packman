<?php

declare(strict_types=1);

namespace Packman\Testkit\Contracts\Observability;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** Minimal metrics recording contract implemented by fake and instrumented test doubles. */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface MetricsRecorderInterface
{
    /** @param array<string, string|int|float|bool> $tags */
    public function incrementCounter(string $name, int $value = 1, array $tags = []): void;

    /** @param array<string, string|int|float|bool> $tags */
    public function recordHistogram(string $name, float $value, array $tags = []): void;

    /** @param array<string, string|int|float|bool> $tags */
    public function recordGauge(string $name, float $value, array $tags = []): void;
}
