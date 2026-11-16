<?php

declare(strict_types=1);

namespace Packman\Errors\Internal;

use BackedEnum;
use Packman\Attributes\Internal;
use Packman\Errors\Contracts\ErrorCode;
use Packman\Errors\Contracts\ProblemRegistry;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Data\ProblemDefinition;
use Packman\Errors\Enums\CoreErrorCode;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Errors\Exceptions\ProblemRegistryException;
use ReflectionClass;

/** Compiled once. No registration or mutation API exists on the read port. */
#[Internal]
final readonly class CompiledProblemRegistry implements ProblemRegistry
{
    /** @var array<string, ProblemDefinition> */
    private array $definitions;

    /** @var array<string, string> */
    private array $owners;

    /** @param iterable<ProblemContribution> $contributions */
    public function __construct(
        iterable $contributions,
        private string $urnPrefix = 'urn:packman:problem:',
    ) {
        $definitions = [];
        $types = [];
        $owners = [];
        foreach ($contributions as $contribution) {
            foreach ($contribution->provider->definitions() as $definition) {
                $code = $definition->code->value();

                if (isset($definitions[$code])) {
                    throw new ProblemRegistryException(errorCode: CoreErrorCode::DuplicateCode, diagnostic: 'ERROR-DUPLICATE-CODE '.$code);
                }

                if (! str_starts_with($code, $contribution->owner.'.')) {
                    throw new ProblemRegistryException(errorCode: CoreErrorCode::ForeignPrefix, diagnostic: 'ERROR-FOREIGN-PREFIX '.$code);
                }

                if ($contribution->sourceRoots !== []) {
                    $file = new ReflectionClass($definition->code)->getFileName();
                    $owned = false;
                    foreach ($contribution->sourceRoots as $root) {
                        $owned = $owned || ($file !== false && str_starts_with($file, $root.DIRECTORY_SEPARATOR));
                    }

                    if (! $owned) {
                        throw new ProblemRegistryException(errorCode: CoreErrorCode::ForeignPrefix, diagnostic: 'ERROR-FOREIGN-TYPE '.$code);
                    }
                }

                if (isset($types[$definition->type])) {
                    throw new ProblemRegistryException(errorCode: CoreErrorCode::DuplicateType, diagnostic: 'ERROR-DUPLICATE-TYPE '.$definition->type);
                }

                if ($definition->defaultStatus < 400 || $definition->defaultStatus > 599) {
                    throw new ProblemRegistryException(errorCode: CoreErrorCode::InvalidStatus, diagnostic: 'ERROR-INVALID-STATUS '.$code);
                }

                if (! $definition->code instanceof BackedEnum
                    || preg_match('/\A[a-z][a-z0-9]*(?:-[a-z0-9]+)*\.[a-z][a-z0-9]*(?:_[a-z0-9]+)*\z/D', $code) !== 1
                    || strlen($code) > ErrorDescriptor::MAX_CODE_BYTES
                    || $definition->type !== $this->urnPrefix.$code
                    || trim($definition->title) === '' || preg_match('/[\x00-\x1F\x7F]/', $definition->title) === 1
                    || $definition->category !== self::categoryForStatus(status: $definition->defaultStatus, declared: $definition->category)) {
                    throw new ProblemRegistryException(errorCode: CoreErrorCode::InvalidDefinition, diagnostic: 'ERROR-INVALID-DEFINITION '.$code);
                }
                foreach ($definition->detailKeys as $key) {
                    if ($key === '' || preg_match('/[\x00-\x1F\x7F]/', $key) === 1) {
                        throw new ProblemRegistryException(errorCode: CoreErrorCode::InvalidDefinition, diagnostic: 'ERROR-INVALID-DEFINITION '.$code);
                    }
                }
                $definitions[$code] = $definition;
                $owners[$code] = $contribution->package ?? ($contribution->owner === 'packman' ? 'axiomasoft/packman' : 'axiomasoft/'.$contribution->owner);
                $types[$definition->type] = true;
            }
        }
        ksort($definitions);
        $this->definitions = $definitions;
        $this->owners = $owners;
    }

    public function get(ErrorCode $code): ProblemDefinition
    {
        $definition = $this->fromWire($code->value());

        if ($definition->code !== $code) {
            throw new ProblemRegistryException(errorCode: CoreErrorCode::UnknownCode, diagnostic: 'ERROR-UNREGISTERED-IDENTITY '.$code->value());
        }

        return $definition;
    }

    public function fromWire(string $code): ProblemDefinition
    {
        return $this->definitions[$code] ?? throw new ProblemRegistryException(errorCode: CoreErrorCode::UnknownCode, diagnostic: 'ERROR-UNKNOWN-CODE');
    }

    public function owner(ErrorCode $code): string
    {
        return $this->owners[$this->get($code)->code->value()];
    }

    public function assertOccurrence(ErrorDescriptor $descriptor, ?int $status = null): void
    {
        $definition = $this->get($descriptor->errorCode);

        if ($descriptor->category !== $definition->category
            || ($descriptor->retryable && ! $definition->retryable)
            || ($status !== null && ($status < 400 || $status > 599
                || ($status !== 404 || $descriptor->category !== ErrorCategory::Authorization)
                    && self::categoryForStatus(status: $status, declared: $descriptor->category) !== $descriptor->category))) {
            throw new ProblemRegistryException(errorCode: CoreErrorCode::InvalidDefinition, diagnostic: 'ERROR-OCCURRENCE-MISMATCH '.$descriptor->code);
        }

        if (! in_array('*', $definition->detailKeys, true) && array_diff(array_keys($descriptor->details), $definition->detailKeys) !== []) {
            throw new ProblemRegistryException(errorCode: CoreErrorCode::InvalidDefinition, diagnostic: 'ERROR-DETAIL-KEY '.$descriptor->code);
        }
    }

    public function all(): array
    {
        return array_values($this->definitions);
    }

    private static function categoryForStatus(int $status, ErrorCategory $declared): ErrorCategory
    {
        return match ($status) {
            401 => ErrorCategory::Authentication,
            403 => ErrorCategory::Authorization,
            404 => ErrorCategory::NotFound,
            410 => in_array($declared, [ErrorCategory::Conflict, ErrorCategory::Validation], true) ? $declared : ErrorCategory::NotFound,
            409 => ErrorCategory::Conflict,
            422 => $declared === ErrorCategory::Unsupported ? $declared : ErrorCategory::Validation,
            429 => ErrorCategory::RateLimit,
            503 => ErrorCategory::Unavailable,
            default => $status < 500 ? ErrorCategory::Validation : ErrorCategory::Internal,
        };
    }
}
