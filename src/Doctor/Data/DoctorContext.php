<?php

declare(strict_types=1);

namespace Packman\Doctor\Data;

use DateTimeImmutable;
use DateTimeZone;
use Packman\Attributes\Api;
use Packman\Doctor\Enums\CheckStatus;
use Packman\Doctor\Enums\Severity;

#[Api(since: '1.0')]
final readonly class DoctorContext
{
    public DateTimeImmutable $now;

    /** @param positive-int $sampleLimit */
    public function __construct(
        public bool $production = false,
        public bool $probeStorage = false,
        public int $sampleLimit = 1000,
        ?DateTimeImmutable $now = null,
    ) {
        $this->now = $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function strictSeverity(): Severity
    {
        return $this->production ? Severity::Error : Severity::Warning;
    }

    public function strictStatus(): CheckStatus
    {
        return $this->production ? CheckStatus::Error : CheckStatus::Warning;
    }

    public function mayProbeStorage(): bool
    {
        return $this->production || $this->probeStorage;
    }
}
