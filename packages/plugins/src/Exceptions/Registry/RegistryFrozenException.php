<?php

declare(strict_types=1);

namespace Packman\Plugins\Exceptions\Registry;

use LogicException;
use Packman\Attributes\Api;
use Packman\Errors\Contracts\DescribesError;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Plugins\Enums\PluginErrorCode;

/**
 * Thrown when a plugin or extension registry is mutated after compilation / boot.
 */
#[Api(since: '1.0')]
final class RegistryFrozenException extends LogicException implements DescribesError
{
    public static function forRegistry(string $registry): self
    {
        return new self("The {$registry} registry is frozen; registrations are only accepted before the graph is compiled.");
    }

    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(code: PluginErrorCode::RegistryFrozen, category: ErrorCategory::Internal, retryable: false);
    }
}
