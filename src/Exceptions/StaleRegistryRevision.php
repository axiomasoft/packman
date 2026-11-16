<?php

declare(strict_types=1);

namespace Packman\Modules\Exceptions;

use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Errors\Exceptions\CodedException;
use Packman\Modules\Enums\ModuleErrorCode;

#[Api(since: '1.0')]
final class StaleRegistryRevision extends CodedException
{
    public static function mismatch(string $expected, string $actual): self
    {
        return new self(sprintf('Registry revision mismatch: expected [%s], current [%s].', $expected, $actual));
    }

    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(code: ModuleErrorCode::StaleRegistryRevision, category: ErrorCategory::Conflict, retryable: false);
    }
}
