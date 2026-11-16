<?php

declare(strict_types=1);

namespace Packman\Filament\Enums;

use Packman\Attributes\Api;
use Packman\Errors\Contracts\ErrorCode;

#[Api(since: '1.0')]
enum FilamentErrorCode: string implements ErrorCode
{
    case AuthorizationDenied = 'filament.authorization_denied';
    case InvalidContext = 'filament.invalid_context';
    case InvalidConfiguration = 'filament.invalid_configuration';
    case NonEditableField = 'filament.non_editable_field';
    case SurfaceRefused = 'filament.surface_refused';
    case PolicyConflict = 'filament.policy_conflict';
    case ValidationFailed = 'filament.validation_failed';

    public function value(): string
    {
        return $this->value;
    }
}
