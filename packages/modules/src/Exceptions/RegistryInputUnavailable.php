<?php

declare(strict_types=1);

namespace Packman\Modules\Exceptions;

use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Errors\Exceptions\CodedException;
use Packman\Modules\Enums\ModuleErrorCode;

#[Api(since: '1.0')]
final class RegistryInputUnavailable extends CodedException
{
    public static function missing(string $path): self
    {
        return new self(sprintf('Compiled module registry is unavailable at [%s].', $path));
    }

    public static function corrupt(): self
    {
        return new self('Compiled module registry payload is corrupt or unreadable.');
    }

    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(code: ModuleErrorCode::RegistryInputUnavailable, category: ErrorCategory::Unavailable, retryable: false);
    }
}
