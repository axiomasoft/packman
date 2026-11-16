<?php

declare(strict_types=1);

namespace Packman\Config\Exceptions;

use InvalidArgumentException;
use Packman\Attributes\Api;
use Packman\Errors\Contracts\DescribesError;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\CoreErrorCode;
use Packman\Errors\Enums\ErrorCategory;

#[Api(since: '1.0')]
final class InvalidConfiguration extends InvalidArgumentException implements DescribesError
{
    /** @param list<string> $violations */
    public function __construct(
        public readonly array $violations,
        ?string $message = null,
    ) {
        parent::__construct(
            $message ?? 'Invalid configuration ('.count($violations).'): '.implode('; ', $violations),
        );
    }

    /** @param non-empty-list<string> $problems */
    public static function fromProblems(array $problems): self
    {
        $count = count($problems);
        $message = 'Invalid configuration ('.$count.' problem'.($count === 1 ? '' : 's')."):\n - ".implode("\n - ", $problems);

        return new self(violations: $problems, message: $message);
    }

    public static function forMessage(string $message): self
    {
        return new self(violations: [$message], message: $message);
    }

    /** @return list<string> */
    public function problems(): array
    {
        return $this->violations;
    }

    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(code: CoreErrorCode::InvalidConfiguration, category: ErrorCategory::Internal, retryable: false);
    }
}
