<?php

declare(strict_types=1);

namespace Packman\Testkit\Fakes\Observability;

use Packman\Attributes\Api;
use Packman\Testkit\Contracts\Observability\TraceRecorderInterface;
use PHPUnit\Framework\Assert;

/** In-memory distributed tracing double capturing spans, attributes, statuses, and events. */
#[Api(since: '1.0')]
final class FakeTraceRecorder implements TraceRecorderInterface
{
    /** @var array<string, array{id: string, name: string, attributes: array<string, string|int|float|bool>, ended: bool, status: ?string, events: list<array{name: string, attributes: array<string, string|int|float|bool>}>}> */
    private array $spans = [];

    private int $sequence = 0;

    public function startSpan(string $name, array $attributes = []): string
    {
        $this->sequence++;
        $spanId = sprintf('span-%04d', $this->sequence);
        $this->spans[$spanId] = [
            'id' => $spanId,
            'name' => $name,
            'attributes' => $attributes,
            'ended' => false,
            'status' => null,
            'events' => [],
        ];

        return $spanId;
    }

    public function endSpan(string $spanId, ?string $status = null): void
    {
        if (! isset($this->spans[$spanId])) {
            return;
        }

        $this->spans[$spanId]['ended'] = true;
        $this->spans[$spanId]['status'] = $status;
    }

    public function recordEvent(string $spanId, string $name, array $attributes = []): void
    {
        if (! isset($this->spans[$spanId])) {
            return;
        }

        $this->spans[$spanId]['events'][] = [
            'name' => $name,
            'attributes' => $attributes,
        ];
    }

    /** @return list<array{id: string, name: string, attributes: array<string, string|int|float|bool>, ended: bool, status: ?string, events: list<array{name: string, attributes: array<string, string|int|float|bool>}>}> */
    public function spans(?string $name = null): array
    {
        $all = array_values($this->spans);

        if ($name === null) {
            return $all;
        }

        return array_values(array_filter(
            $all,
            static fn (array $span): bool => $span['name'] === $name,
        ));
    }

    public function assertSpanRecorded(string $name, bool $requireEnded = true): void
    {
        $matching = $this->spans($name);
        Assert::assertNotEmpty($matching, "Expected trace span [{$name}] to be recorded.");

        if ($requireEnded) {
            $ended = array_filter($matching, static fn (array $span): bool => $span['ended']);
            Assert::assertNotEmpty($ended, "Expected at least one ended trace span [{$name}].");
        }
    }

    public function reset(): void
    {
        $this->spans = [];
        $this->sequence = 0;
    }
}
