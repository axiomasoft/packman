<?php

declare(strict_types=1);

namespace Packman\Testkit\Fakes\Observability;

use Packman\Attributes\Api;
use Packman\Testkit\Contracts\Observability\MetricsRecorderInterface;
use PHPUnit\Framework\Assert;

/** In-memory metrics double recording counters, histograms, and gauges for assertions. */
#[Api(since: '1.0')]
final class FakeMetricsRecorder implements MetricsRecorderInterface
{
    /** @var list<array{name: string, value: int, tags: array<string, string|int|float|bool>}> */
    private array $counters = [];

    /** @var list<array{name: string, value: float, tags: array<string, string|int|float|bool>}> */
    private array $histograms = [];

    /** @var list<array{name: string, value: float, tags: array<string, string|int|float|bool>}> */
    private array $gauges = [];

    public function incrementCounter(string $name, int $value = 1, array $tags = []): void
    {
        $this->counters[] = ['name' => $name, 'value' => $value, 'tags' => $tags];
    }

    public function recordHistogram(string $name, float $value, array $tags = []): void
    {
        $this->histograms[] = ['name' => $name, 'value' => $value, 'tags' => $tags];
    }

    public function recordGauge(string $name, float $value, array $tags = []): void
    {
        $this->gauges[] = ['name' => $name, 'value' => $value, 'tags' => $tags];
    }

    public function counterTotal(string $name): int
    {
        $total = 0;
        foreach ($this->counters as $entry) {
            if ($entry['name'] === $name) {
                $total += $entry['value'];
            }
        }

        return $total;
    }

    /** @return list<array{name: string, value: float, tags: array<string, string|int|float|bool>}> */
    public function histograms(string $name): array
    {
        return array_values(array_filter(
            $this->histograms,
            static fn (array $entry): bool => $entry['name'] === $name,
        ));
    }

    /** @return list<array{name: string, value: float, tags: array<string, string|int|float|bool>}> */
    public function gauges(string $name): array
    {
        return array_values(array_filter(
            $this->gauges,
            static fn (array $entry): bool => $entry['name'] === $name,
        ));
    }

    public function assertCounter(string $name, int $expectedTotal): void
    {
        Assert::assertSame($expectedTotal, $this->counterTotal($name), "Unexpected total for counter [{$name}].");
    }

    public function reset(): void
    {
        $this->counters = [];
        $this->histograms = [];
        $this->gauges = [];
    }
}
