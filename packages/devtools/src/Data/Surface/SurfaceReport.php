<?php

declare(strict_types=1);

namespace Packman\DevTools\Data\Surface;

use Packman\Attributes\Api;

/**
 * Immutable result of scanning one package's exported API/SPI surface and diagnostics.
 */
#[Api(since: '1.0')]
final readonly class SurfaceReport
{
    /**
     * @param  array<string, array<string, mixed>>  $symbols
     * @param  list<SurfaceDiagnostic>  $diagnostics
     */
    public function __construct(
        public string $packageName,
        public array $symbols,
        public array $diagnostics,
    ) {}

    public function ok(): bool
    {
        return $this->diagnostics === [];
    }

    /** @return array{schema: string, package: string, symbols: array<string, array<string, mixed>>} */
    public function toManifestArray(): array
    {
        return [
            'schema' => 'php-api/1',
            'package' => $this->packageName,
            'symbols' => $this->symbols,
        ];
    }
}
