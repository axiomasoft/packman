<?php

declare(strict_types=1);

namespace Packman\Modules\Exceptions;

use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Errors\Exceptions\CodedException;
use Packman\Modules\Enums\ModuleErrorCode;

#[Api(since: '1.0')]
final class InvalidRegistryPoint extends CodedException
{
    public static function unknown(string $name): self
    {
        return new self(sprintf('Unknown registry extension point [%s].', $name));
    }

    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(code: ModuleErrorCode::InvalidRegistryPoint, category: ErrorCategory::Validation, retryable: false);
    }
}
