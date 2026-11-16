<?php

declare(strict_types=1);

namespace Packman\Doctor\Data;

use DateTimeImmutable;
use DateTimeZone;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class DoctorReport
{
    public const string SCHEMA = 'packman.doctor/v1';

    /**
     * @param  list<CheckResult>  $checks
     * @param  array<string, array<string, mixed>>  $slices
     */
    public function __construct(
        public bool $production,
        public DateTimeImmutable $generatedAt,
        public array $checks,
        public array $slices = [],
    ) {}

    /** @return array{ok: int, warning: int, error: int, skipped: int} */
    public function summary(): array
    {
        $summary = ['ok' => 0, 'warning' => 0, 'error' => 0, 'skipped' => 0];

        foreach ($this->checks as $check) {
            foreach ($check->findings as $finding) {
                $summary[$finding->status->value]++;
            }
        }

        return $summary;
    }

    /** Any error fails the run; warnings and skipped checks do not. */
    public function exitCode(): int
    {
        return $this->summary()['error'] > 0 ? 1 : 0;
    }

    public function ok(): bool
    {
        return $this->exitCode() === 0;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'ok' => $this->ok(),
            'production' => $this->production,
            'generated_at' => $this->generatedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'summary' => $this->summary(),
            'checks' => array_map(static fn (CheckResult $check): array => [
                'key' => $check->key,
                'title' => $check->title,
                'status' => $check->status->value,
                'findings' => array_map(static fn (DoctorFinding $finding): array => $finding->toArray(), $check->findings),
            ], $this->checks),
            'slices' => (object) $this->slices,
            'exit_code' => $this->exitCode(),
        ];
    }
}
