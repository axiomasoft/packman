<?php

declare(strict_types=1);

namespace Packman\Schema\Tests\Fixtures;

use Packman\Schema\Contracts\SchemaComponent;
use Packman\Schema\Contracts\SchemaStep;
use Packman\Schema\Data\SchemaSpace;

final class FixtureComponent implements SchemaComponent
{
    /**
     * @param  list<string>  $tables
     * @param  array<string, class-string<SchemaStep>>  $steps
     * @param  list<string>  $dependsOn
     */
    public function __construct(
        private string $id = 'fixture',
        private array $tables = ['fixture'],
        private array $steps = ['2026_10_01_000900_create_fixture' => FixtureStep::class],
        private array $dependsOn = [],
        private ?string $onlySpace = null,
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function tables(): array
    {
        return $this->tables;
    }

    public function steps(): array
    {
        return $this->steps;
    }

    public function dependsOn(): array
    {
        return $this->dependsOn;
    }

    public function appliesTo(SchemaSpace $space): bool
    {
        return $this->onlySpace === null || $this->onlySpace === $space->id;
    }
}
