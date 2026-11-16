<?php

declare(strict_types=1);

namespace Packman\Schema\Tests\Fixtures;

use Packman\Schema\Contracts\Migration\SchemaSetProvider;
use Packman\Schema\Data\Migration\SchemaSet;

final readonly class FixtureSchemaSets implements SchemaSetProvider
{
    /** @param list<SchemaSet> $declared */
    public function __construct(private array $declared) {}

    public function sets(): array
    {
        return $this->declared;
    }
}
