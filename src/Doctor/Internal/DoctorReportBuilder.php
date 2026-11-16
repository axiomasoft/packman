<?php

declare(strict_types=1);

namespace Packman\Doctor\Internal;

use Packman\Attributes\Internal;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Doctor\Contracts\DoctorRegistryContract;
use Packman\Doctor\Data\CheckResult;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Data\DoctorFinding;
use Packman\Doctor\Data\DoctorReport;
use Packman\Doctor\Enums\CheckStatus;
use Throwable;

/**
 * Runs selected DoctorCheck and HealthCheck instances with exception isolation,
 * secret message stripping, evidence bounding, and database-failure cascade skipping.
 */
#[Internal]
final readonly class DoctorReportBuilder
{
    public const int MAX_EVIDENCE_ENTRIES = 20;

    public const int MAX_EVIDENCE_LENGTH = 200;

    public function __construct(private DoctorRegistryContract $registry) {}

    /**
     * @param  list<string>  $prefixes  `--prefix` / `--check` filter; empty runs all checks
     * @param  bool  $exposeMessages  keep failed check exception message in `CheckResult::$detail` (verbose mode only)
     */
    public function build(DoctorContext $context, array $prefixes = [], bool $exposeMessages = false): DoctorReport
    {
        $results = [];
        $connectionFailed = false;

        foreach ($this->registry->matching($prefixes) as $check) {
            $result = $this->runCheck($check, $context, $exposeMessages, $connectionFailed);
            $results[] = $result;

            if ($this->hasConnectionFailure($result->findings)) {
                $connectionFailed = true;
            }
        }

        $slices = [];

        if ($prefixes === []) {
            foreach ($this->registry->healthChecks() as $healthCheck) {
                $code = $healthCheck->code();
                $normalizedKey = $this->normalizeHealthKey($code);

                if ($connectionFailed && $healthCheck->requiresDatabase()) {
                    $slices[$code] = ['status' => 'skipped_connection_failed'];
                    $results[] = new CheckResult(
                        key: $normalizedKey,
                        title: $code,
                        status: CheckStatus::Skipped,
                        findings: [
                            DoctorFinding::skipped(
                                code: 'doctor.skipped_connection_failed',
                                message: 'Skipped because database connection failed.',
                                slice: $code,
                            ),
                        ],
                    );

                    continue;
                }

                try {
                    [$sliceFindings, $payload] = $healthCheck->slice($context);
                    $bounded = array_map($this->boundedFinding(...), $sliceFindings);
                    $slices[$code] = $payload;

                    if ($this->hasConnectionFailure($bounded)) {
                        $connectionFailed = true;
                    }

                    if ($bounded !== []) {
                        $results[] = new CheckResult(
                            key: $normalizedKey,
                            title: $code,
                            status: $this->worstStatus($bounded),
                            findings: $bounded,
                        );
                    }
                } catch (Throwable $exception) {
                    $slices[$code] = ['status' => 'failed'];
                    $results[] = new CheckResult(
                        key: $normalizedKey,
                        title: $code,
                        status: CheckStatus::Error,
                        findings: [
                            DoctorFinding::error(
                                code: 'doctor.check_failed',
                                message: $exception::class,
                                remediation: 'Run with -v to inspect the exception detail.',
                                slice: $code,
                            ),
                        ],
                        detail: $exposeMessages ? $exception->getMessage() : null,
                    );
                }
            }
        }

        return new DoctorReport(
            production: $context->production,
            generatedAt: $context->now,
            checks: $results,
            slices: $slices,
        );
    }

    private function runCheck(
        DoctorCheck $check,
        DoctorContext $context,
        bool $exposeMessages,
        bool $connectionFailed,
    ): CheckResult {
        $key = $check->key();

        if ($connectionFailed && str_contains($key, '.schema')) {
            return new CheckResult(
                key: $key,
                title: $this->titleOrKey($check),
                status: CheckStatus::Skipped,
                findings: [
                    DoctorFinding::skipped(
                        code: 'doctor.skipped_connection_failed',
                        message: 'Skipped because the database connection failed.',
                    ),
                ],
            );
        }

        try {
            $title = $check->title();
            $findings = [];
            foreach ($check->run($context) as $finding) {
                $findings[] = $this->boundedFinding($finding);
            }
        } catch (Throwable $exception) {
            return new CheckResult(
                key: $key,
                title: $this->titleOrKey($check),
                status: CheckStatus::Error,
                findings: [
                    DoctorFinding::error(
                        code: 'doctor.check_failed',
                        message: $exception::class,
                        remediation: 'run with -v',
                    ),
                ],
                detail: $exposeMessages ? $exception->getMessage() : null,
            );
        }

        if ($findings === []) {
            $findings = [
                DoctorFinding::error(
                    code: 'doctor.no_findings',
                    message: 'The check returned no findings.',
                    remediation: 'Return at least one ok finding when no issues are found.',
                ),
            ];
        }

        return new CheckResult(
            key: $key,
            title: $title,
            status: $this->worstStatus($findings),
            findings: $findings,
        );
    }

    private function titleOrKey(DoctorCheck $check): string
    {
        try {
            return $check->title();
        } catch (Throwable) {
            return $check->key();
        }
    }

    private function boundedFinding(DoctorFinding $finding): DoctorFinding
    {
        $evidence = [];
        foreach (array_slice($finding->evidence, 0, self::MAX_EVIDENCE_ENTRIES, true) as $name => $value) {
            $evidence[$name] = self::scalar($value);
        }

        return new DoctorFinding(
            status: $finding->status,
            severity: $finding->severity,
            code: $finding->code,
            message: $finding->message,
            remediation: $finding->remediation,
            evidence: $evidence,
            slice: $finding->slice,
        );
    }

    private static function scalar(mixed $value): string|int|float|bool|null
    {
        if (is_string($value)) {
            return mb_strlen($value) > self::MAX_EVIDENCE_LENGTH
                ? mb_substr($value, 0, self::MAX_EVIDENCE_LENGTH - 1).'…'
                : $value;
        }

        if (is_float($value) && ! is_finite($value)) {
            return 'non-finite-float';
        }

        return is_scalar($value) || $value === null ? $value : get_debug_type($value);
    }

    /** @param non-empty-list<DoctorFinding> $findings */
    private function worstStatus(array $findings): CheckStatus
    {
        $status = CheckStatus::Ok;
        foreach ($findings as $finding) {
            if ($finding->status === CheckStatus::Error) {
                return CheckStatus::Error;
            }

            if ($finding->status === CheckStatus::Warning) {
                $status = CheckStatus::Warning;
            } elseif ($finding->status === CheckStatus::Skipped && $status === CheckStatus::Ok) {
                $status = CheckStatus::Skipped;
            }
        }

        return $status;
    }

    /** @param list<DoctorFinding> $findings */
    private function hasConnectionFailure(array $findings): bool
    {
        return array_any($findings, fn (DoctorFinding $finding): bool => $finding->status === CheckStatus::Error && $this->isConnectionFailureCode($finding->code));
    }

    private function isConnectionFailureCode(string $code): bool
    {
        return str_ends_with($code, 'connection_failed') || $code === 'DOC-CONNECT-001';
    }

    private function normalizeHealthKey(string $code): string
    {
        $normalized = preg_replace('/[^a-z0-9_.]+/', '_', strtolower($code));
        $normalized = trim($normalized ?? 'health', '._');

        return $normalized !== '' ? $normalized : 'health';
    }
}
