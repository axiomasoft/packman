<?php

declare(strict_types=1);

namespace Packman\Modules\Exceptions;

use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Errors\Exceptions\CodedException;
use Packman\Modules\Contracts\ModuleLifecycle;
use Packman\Modules\Enums\ModuleErrorCode;
use Throwable;

/**
 * A module migration or EnableHook failed during {@see ModuleLifecycle::enable()}.
 *
 * enable() is all-or-nothing: the failure rolls the WHOLE lifecycle
 * transaction back — no partially-applied migrations, no `mod_modules` row —
 * and rethrows this so the caller (a control-plane job) learns of it instead
 * of a module silently stuck half-installed. A retry after the fix starts from
 * a clean state.
 */
#[Api(since: '1.0')]
final class ModuleEnableFailedException extends CodedException
{
    public function __construct(string $module, Throwable $previous)
    {
        parent::__construct(
            sprintf('Enabling module [%s] failed and was rolled back: %s', $module, $previous->getMessage()),
            previous: $previous,
        );
    }

    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(code: ModuleErrorCode::ModuleEnableFailedException, category: ErrorCategory::Internal, retryable: false);
    }
}
