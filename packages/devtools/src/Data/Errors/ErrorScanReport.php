<?php

declare(strict_types=1);

namespace Packman\DevTools\Data\Errors;

use Packman\Attributes\Api;

/** Aggregated error registry catalogue and validation diagnostics for a scan run. */
#[Api(since: '1.0')]
final readonly class ErrorScanReport
{
    /**
     * @param  list<ErrorCodeEntry>  $entries
     * @param  list<string>  $diagnostics
     */
    public function __construct(
        public array $entries,
        public array $diagnostics,
    ) {}

    public function ok(): bool
    {
        return $this->diagnostics === [];
    }
}
