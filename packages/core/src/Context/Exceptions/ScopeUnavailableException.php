<?php

declare(strict_types=1);

namespace Packman\Context\Exceptions;

use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\CoreErrorCode;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Errors\Exceptions\CodedException;

/**
 * Thrown when an operation requires an initialized scope context and none is currently active.
 */
#[Api(since: '1.0')]
class ScopeUnavailableException extends CodedException
{
    public function __construct(
        string $message = 'No active context scope is available for this operation.',
    ) {
        parent::__construct($message);
    }

    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: CoreErrorCode::ScopeUnavailable,
            category: ErrorCategory::Conflict,
            retryable: false,
        );
    }
}
