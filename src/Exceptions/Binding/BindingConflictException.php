<?php

declare(strict_types=1);

namespace Packman\Filament\Exceptions\Binding;

use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Filament\Enums\FilamentErrorCode;
use Packman\Filament\Exceptions\FilamentException;

#[Api(since: '1.0')]
final class BindingConflictException extends FilamentException
{
    public static function staleVersion(string $expected, string $actual): self
    {
        return new self('Binding edit version mismatch: expected "'.$expected.'", actual "'.$actual.'".');
    }

    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(code: FilamentErrorCode::PolicyConflict, category: ErrorCategory::Conflict, retryable: true);
    }
}
