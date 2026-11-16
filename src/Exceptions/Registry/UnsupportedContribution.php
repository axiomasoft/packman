<?php

declare(strict_types=1);

namespace Packman\Plugins\Exceptions\Registry;

use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Errors\Exceptions\CodedException;
use Packman\Plugins\Enums\PluginErrorCode;

/** A plugin contributed a kind or key the installed packages do not support. */
#[Api(since: '1.0')]
final class UnsupportedContribution extends CodedException
{
    public function __construct(
        public readonly string $kind,
        public readonly string $key,
        public readonly string $reason,
    ) {
        parent::__construct("Unsupported contribution {$kind}/{$key}: {$reason}.");
    }

    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: PluginErrorCode::UnsupportedContribution,
            category: ErrorCategory::Unsupported,
            retryable: false,
            details: ['kind' => $this->kind, 'key' => $this->key, 'reason' => $this->reason],
        );
    }
}
