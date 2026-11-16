<?php

declare(strict_types=1);

namespace Packman\Plugins\Exceptions\Graph;

use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Errors\Exceptions\CodedException;
use Packman\Plugins\Enums\PluginErrorCode;

/**
 * A capability or slot handler was requested, but no active plugin provides it.
 */
#[Api(since: '1.0')]
final class MissingExtensionException extends CodedException
{
    public static function capability(string $contract): self
    {
        return new self(sprintf(
            '[Packman] No active plugin provides %s. Install and activate the providing plugin or check has() first.',
            $contract,
        ));
    }

    public static function notSingle(string $contract): self
    {
        return new self(sprintf(
            '[Packman] %s takes many providers. Use all() instead of require().',
            $contract,
        ));
    }

    public static function handler(string $slot, string $key): self
    {
        return new self(sprintf(
            '[Packman] No active plugin contributes "%s" to %s. List available keys with handlerKeys().',
            $key,
            $slot,
        ));
    }

    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(code: PluginErrorCode::MissingExtension, category: ErrorCategory::Unsupported, retryable: false);
    }
}
