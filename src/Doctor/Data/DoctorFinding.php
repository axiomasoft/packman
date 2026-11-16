<?php

declare(strict_types=1);

namespace Packman\Doctor\Data;

use InvalidArgumentException;
use Packman\Attributes\Api;
use Packman\Doctor\Enums\CheckStatus;
use Packman\Doctor\Enums\Severity;

/**
 * One read-only diagnostic finding. Enforces non-empty remediation for warnings/errors
 * and scalar-only evidence values so secrets or nested objects never leak into reports.
 */
#[Api(since: '1.0')]
final readonly class DoctorFinding
{
    /** @var array<string, scalar|null> */
    public array $evidence;

    /** @param array<array-key, mixed> $evidence */
    public function __construct(
        public CheckStatus $status,
        public Severity $severity,
        public string $code,
        public string $message,
        public string $remediation = '',
        array $evidence = [],
        public ?string $slice = null,
    ) {
        if ($this->code === '') {
            throw new InvalidArgumentException('DoctorFinding code must be non-empty.');
        }

        if (($this->status === CheckStatus::Warning || $this->status === CheckStatus::Error) && trim($this->remediation) === '') {
            throw new InvalidArgumentException("DoctorFinding [{$this->code}] requires a non-empty remediation.");
        }

        $validated = [];
        foreach ($evidence as $key => $value) {
            if (! is_string($key) || $key === '' || (is_float($value) && ! is_finite($value)) || (! is_scalar($value) && $value !== null)) {
                throw new InvalidArgumentException('DoctorFinding evidence must contain string keys and finite scalar or null values only.');
            }
            $validated[$key] = $value;
        }
        $this->evidence = $validated;
    }

    /** @param array<string, scalar|null> $evidence */
    public static function ok(
        string $code,
        string $message,
        string $remediation = '',
        array $evidence = [],
        ?string $slice = null,
    ): self {
        return new self(
            status: CheckStatus::Ok,
            severity: Severity::Info,
            code: $code,
            message: $message,
            remediation: $remediation,
            evidence: $evidence,
            slice: $slice,
        );
    }

    /** @param array<string, scalar|null> $evidence */
    public static function warning(
        string $code,
        string $message,
        string $remediation,
        array $evidence = [],
        ?string $slice = null,
    ): self {
        return new self(
            status: CheckStatus::Warning,
            severity: Severity::Warning,
            code: $code,
            message: $message,
            remediation: $remediation,
            evidence: $evidence,
            slice: $slice,
        );
    }

    /** @param array<string, scalar|null> $evidence */
    public static function error(
        string $code,
        string $message,
        string $remediation,
        array $evidence = [],
        ?string $slice = null,
    ): self {
        return new self(
            status: CheckStatus::Error,
            severity: Severity::Error,
            code: $code,
            message: $message,
            remediation: $remediation,
            evidence: $evidence,
            slice: $slice,
        );
    }

    /** @param array<string, scalar|null> $evidence */
    public static function skipped(
        string $code,
        string $message,
        string $remediation = '',
        array $evidence = [],
        ?string $slice = null,
    ): self {
        return new self(
            status: CheckStatus::Skipped,
            severity: Severity::Info,
            code: $code,
            message: $message,
            remediation: $remediation,
            evidence: $evidence,
            slice: $slice,
        );
    }

    /** @param array<string, scalar|null> $evidence */
    public static function at(
        CheckStatus $status,
        Severity $severity,
        string $code,
        string $message,
        string $remediation = '',
        array $evidence = [],
        ?string $slice = null,
    ): self {
        return new self(
            status: $status,
            severity: $severity,
            code: $code,
            message: $message,
            remediation: $remediation,
            evidence: $evidence,
            slice: $slice,
        );
    }

    /** @return array{status: string, severity: string, code: string, message: string, remediation: string, slice: ?string, evidence: object} */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'severity' => $this->severity->value,
            'code' => $this->code,
            'message' => $this->message,
            'remediation' => $this->remediation,
            'slice' => $this->slice,
            'evidence' => (object) $this->evidence,
        ];
    }
}
