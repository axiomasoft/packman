<?php

declare(strict_types=1);

namespace Packman\Modules\Data;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class RegistryMap
{
    /**
     * @param  list<array{kind: string, name?: string, entryCount?: int, owners?: array<string, int>, handle?: string, module?: string, capabilities?: list<string>, entityHandle?: string, sleeping?: bool}>  $extensionPoints
     */
    public function __construct(
        public string $schemaVersion,
        public string $revision,
        public array $extensionPoints,
    ) {}
}
