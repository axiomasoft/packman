<?php

declare(strict_types=1);

namespace Packman\Schema\Exceptions;

use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\CoreErrorCode;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Errors\Exceptions\CodedException;

/**
 * Schema components and spaces cannot be compiled together. Thrown at boot with every problem at once.
 */
#[Api(since: '1.0')]
final class InvalidSchemaException extends CodedException
{
    /** @var list<string> */
    private array $problems = [];

    /** @param  non-empty-list<string>  $problems */
    public static function fromProblems(array $problems): self
    {
        $problems = array_values(array_unique($problems));
        sort($problems);
        $count = count($problems);
        $message = 'Invalid schema ('.$count.' problem'.($count === 1 ? '' : 's')."):\n - ".implode("\n - ", $problems);

        $exception = new self($message);
        $exception->problems = $problems;

        return $exception;
    }

    /** @return list<string> */
    public function problems(): array
    {
        return $this->problems;
    }

    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(code: CoreErrorCode::SchemaInvalidSet, category: ErrorCategory::Internal, retryable: false);
    }
}
