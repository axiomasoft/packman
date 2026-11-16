<?php

declare(strict_types=1);

namespace Packman\Panels\Exceptions\SavedViews;

use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Panels\Enums\PanelErrorCode;
use Packman\Panels\Exceptions\PanelException;

#[Api(since: '1.0')]
final class SavedViewException extends PanelException
{
    private function __construct(
        string $message,
        private readonly PanelErrorCode $errorCode,
        private readonly ErrorCategory $category,
    ) {
        parent::__construct($message);
    }

    public static function conflict(string $message = 'Saved view revision conflict.'): self
    {
        return new self($message, PanelErrorCode::SavedViewConflict, ErrorCategory::Conflict);
    }

    public static function notFound(string $message = 'Saved view not found.'): self
    {
        return new self($message, PanelErrorCode::SavedViewNotFound, ErrorCategory::NotFound);
    }

    public static function denied(string $message = 'Saved view action denied.'): self
    {
        return new self($message, PanelErrorCode::SavedViewAccessDenied, ErrorCategory::Authorization);
    }

    public static function invalid(string $message = 'Unsupported saved view schema.'): self
    {
        return new self($message, PanelErrorCode::InvalidSavedView, ErrorCategory::Validation);
    }

    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(code: $this->errorCode, category: $this->category, retryable: false);
    }
}
