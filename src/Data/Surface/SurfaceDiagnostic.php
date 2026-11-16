<?php

declare(strict_types=1);

namespace Packman\DevTools\Data\Surface;

use Packman\Attributes\Api;

/**
 * Diagnostic reported when scanning package public/SPI/internal attribute annotations.
 */
#[Api(since: '1.0')]
final readonly class SurfaceDiagnostic
{
    public function __construct(
        public string $code,
        public string $symbol,
        public string $file,
        public int $line,
        public string $message,
    ) {}

    public function format(): string
    {
        return sprintf('[%s] %s (%s:%d): %s', $this->code, $this->symbol, $this->file, $this->line, $this->message);
    }
}
