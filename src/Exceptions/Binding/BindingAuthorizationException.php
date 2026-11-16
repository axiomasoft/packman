<?php

declare(strict_types=1);

namespace Packman\Filament\Exceptions\Binding;

use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Filament\Enums\FilamentErrorCode;
use Packman\Filament\Exceptions\FilamentException;

#[Api(since: '1.0')]
final class BindingAuthorizationException extends FilamentException
{
    public function __construct(
        string $message = 'Binding action denied.',
        private readonly FilamentErrorCode $errorCode = FilamentErrorCode::AuthorizationDenied,
    ) {
        parent::__construct($message);
    }

    public static function invalidContext(string $message = 'Invalid or tampered binding context.'): self
    {
        return new self($message, FilamentErrorCode::InvalidContext);
    }

    public static function nonEditable(string $field): self
    {
        return new self('Field "'.$field.'" is not editable.', FilamentErrorCode::NonEditableField);
    }

    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(code: $this->errorCode, category: ErrorCategory::Authorization, retryable: false);
    }
}
