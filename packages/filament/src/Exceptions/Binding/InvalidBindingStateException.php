<?php

declare(strict_types=1);

namespace Packman\Filament\Exceptions\Binding;

use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Filament\Enums\FilamentErrorCode;
use Packman\Filament\Exceptions\FilamentException;

#[Api(since: '1.0')]
final class InvalidBindingStateException extends FilamentException
{
    public function __construct(
        string $message = 'Invalid binding state.',
        private readonly FilamentErrorCode $errorCode = FilamentErrorCode::ValidationFailed,
        private readonly ErrorCategory $category = ErrorCategory::Validation,
    ) {
        parent::__construct($message);
    }

    public static function invalidPatch(string $message = 'Invalid binding patch payload.'): self
    {
        return new self($message, FilamentErrorCode::ValidationFailed, ErrorCategory::Validation);
    }

    public static function invalidConfiguration(string $message = 'Invalid Filament plugin configuration.'): self
    {
        return new self($message, FilamentErrorCode::InvalidConfiguration, ErrorCategory::Internal);
    }

    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(code: $this->errorCode, category: $this->category, retryable: false);
    }
}
