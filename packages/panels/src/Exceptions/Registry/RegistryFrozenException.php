<?php

declare(strict_types=1);

namespace Packman\Panels\Exceptions\Registry;

use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Panels\Enums\PanelErrorCode;
use Packman\Panels\Exceptions\Definition\DefinitionException;

/**
 * The panel registry or a compiled panel builder is mutated after the application has booted.
 */
#[Api(since: '1.0')]
final class RegistryFrozenException extends DefinitionException
{
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(code: PanelErrorCode::RegistryFrozen, category: ErrorCategory::Conflict, retryable: false);
    }
}
