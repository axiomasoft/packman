<?php

declare(strict_types=1);

namespace Packman\Modules\Exceptions;

use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Errors\Exceptions\CodedException;
use Packman\Modules\Enums\ModuleErrorCode;

#[Api(since: '1.0')]
final class EntityNotFound extends CodedException
{
    public function __construct(string $handle)
    {
        parent::__construct(sprintf('Entity [%s] is not registered.', $handle));
    }

    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(code: ModuleErrorCode::EntityNotFound, category: ErrorCategory::NotFound, retryable: false);
    }
}
