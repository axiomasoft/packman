<?php

declare(strict_types=1);

namespace Packman\DevTools\Data\Gates;

use Packman\Attributes\Api;

/** Immutable pass/fail outcome returned by one QualityGate. */
#[Api(since: '1.0')]
final readonly class GateResult
{
    /**
     * @param  list<string>  $violations
     * @param  list<string>  $notes
     */
    public function __construct(
        public string $gateId,
        public string $category,
        public bool $passed,
        public array $violations = [],
        public array $notes = [],
        public float $durationMs = 0.0,
    ) {}

    /** @param list<string> $notes */
    public static function pass(string $gateId, string $category, array $notes = [], float $durationMs = 0.0): self
    {
        return new self(
            gateId: $gateId,
            category: $category,
            passed: true,
            violations: [],
            notes: $notes,
            durationMs: $durationMs,
        );
    }

    /**
     * @param  list<string>  $violations
     * @param  list<string>  $notes
     */
    public static function fail(string $gateId, string $category, array $violations, array $notes = [], float $durationMs = 0.0): self
    {
        return new self(
            gateId: $gateId,
            category: $category,
            passed: false,
            violations: $violations,
            notes: $notes,
            durationMs: $durationMs,
        );
    }

    public function withDuration(float $durationMs): self
    {
        return new self(
            gateId: $this->gateId,
            category: $this->category,
            passed: $this->passed,
            violations: $this->violations,
            notes: $this->notes,
            durationMs: $durationMs,
        );
    }
}
