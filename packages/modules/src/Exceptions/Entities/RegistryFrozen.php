<?php

declare(strict_types=1);

namespace Packman\Modules\Exceptions\Entities;

use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Errors\Exceptions\CodedException;
use Packman\Modules\Enums\ModuleErrorCode;

/**
 * Late or duplicate entity registration after the compiled projection froze.
 */
#[Api(since: '1.0')]
final class RegistryFrozen extends CodedException
{
    public static function late(string $point, string $id): self
    {
        return new self(sprintf(
            'Cannot register [%s:%s] after the entity collector is frozen.',
            $point,
            $id,
        ));
    }

    public static function duplicate(string $point, string $id): self
    {
        return new self(sprintf(
            'Duplicate [%s:%s] registration is rejected before mutation.',
            $point,
            $id,
        ));
    }

    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(code: ModuleErrorCode::RegistryFrozen, category: ErrorCategory::Internal, retryable: false);
    }
}
