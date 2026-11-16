<?php

declare(strict_types=1);

namespace Packman\Testkit\Contracts\Observability;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** Minimal trace recording contract implemented by fake and instrumented test doubles. */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface TraceRecorderInterface
{
    /** @param array<string, string|int|float|bool> $attributes */
    public function startSpan(string $name, array $attributes = []): string;

    public function endSpan(string $spanId, ?string $status = null): void;

    /** @param array<string, string|int|float|bool> $attributes */
    public function recordEvent(string $spanId, string $name, array $attributes = []): void;
}
