<?php

declare(strict_types=1);

namespace Packman\Errors\Exceptions;

use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\CoreErrorCode;
use Packman\Errors\Enums\ErrorCategory;

#[Api(since: '1.0')]
final class ProblemRegistryException extends CodedException
{
    public function __construct(public readonly CoreErrorCode $errorCode, string $diagnostic)
    {
        parent::__construct($diagnostic);
    }

    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(code: $this->errorCode, category: ErrorCategory::Internal);
    }
}
