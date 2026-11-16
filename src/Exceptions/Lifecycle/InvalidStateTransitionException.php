<?php

declare(strict_types=1);

namespace Packman\Modules\Exceptions\Lifecycle;

use LogicException;
use Packman\Attributes\Api;
use Packman\Errors\Contracts\DescribesError;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Modules\Enums\ModuleErrorCode;

/**
 * An FSM transition was requested that the module's current state forbids.
 * `purged` is terminal — re-installing a purged module goes through
 * `modules:repair` (which returns it to `available`), never a direct
 * `enable` on the dead schema; `upgrade` requires `enabled`.
 */
#[Api(since: '1.0')]
final class InvalidStateTransitionException extends LogicException implements DescribesError
{
    public static function purgedIsTerminal(string $module): self
    {
        return new self(sprintf(
            'Module [%s] is purged (terminal): its schema is gone. Run `modules:repair %s` to reset it to available before enabling again — enabling directly would flip the row to enabled over dropped tables (A11).',
            $module,
            $module,
        ));
    }

    public static function upgradeRequiresEnabled(string $module, string $state): self
    {
        return new self(sprintf(
            'Module [%s] must be enabled to upgrade (current state: %s) — B-10 §5.5.',
            $module,
            $state,
        ));
    }

    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(code: ModuleErrorCode::InvalidStateTransitionException, category: ErrorCategory::Conflict, retryable: false);
    }
}
