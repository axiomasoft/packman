<?php

declare(strict_types=1);

namespace Packman\Modules\Exceptions\Lifecycle;

use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Errors\Exceptions\CodedException;
use Packman\Modules\Enums\ModuleErrorCode;

#[Api(since: '1.0')]
final class HasEnabledDependentsException extends CodedException
{
    /** @param  list<string>  $dependents */
    public function __construct(string $module, array $dependents)
    {
        parent::__construct(sprintf(
            'Module [%s] has enabled dependents [%s]. Pass cascade: true to disable them first.',
            $module,
            implode(', ', $dependents),
        ));
    }

    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(code: ModuleErrorCode::HasEnabledDependentsException, category: ErrorCategory::Conflict, retryable: false);
    }
}
