<?php

declare(strict_types=1);

namespace Packman\Schema\Internal\Migration;

use Packman\Attributes\Internal;
use Packman\Schema\Contracts\Migration\SchemaSets;
use Packman\Schema\Data\Migration\SchemaSet;
use Packman\Schema\Enums\SchemaLifecycle;
use Packman\Schema\Exceptions\SchemaSetException;

/** @internal */
#[Internal]
final class SchemaMigrationPlan
{
    /** @var array<string, int> */
    private array $levels = [];

    /** @var array<string, true> */
    private array $visiting = [];

    /** @var array<int, array<string, SchemaSet>> */
    private array $batches = [];

    public function __construct(
        private readonly SchemaSets $sets,
        private readonly string $driver,
        private readonly ?SchemaSet $bootstrap,
    ) {}

    /**
     * Laravel sorts migration files by timestamp inside a batch. A dependency
     * must therefore run in an earlier batch even if its filenames sort later.
     *
     * @param  list<SchemaSet>  $selection
     * @return list<list<SchemaSet>>
     */
    public function batches(array $selection): array
    {
        foreach ($selection as $set) {
            $this->level($set);
        }

        ksort($this->batches);
        $result = [];
        foreach ($this->batches as $batch) {
            ksort($batch, SORT_STRING);
            $result[] = array_values($batch);
        }

        return $result;
    }

    private function level(SchemaSet $set): int
    {
        $id = $set->id();

        if (isset($this->levels[$id])) {
            return $this->levels[$id];
        }

        if (isset($this->visiting[$id])) {
            throw SchemaSetException::invalidPrerequisite($id, 'cycle '.implode(' -> ', [...array_keys($this->visiting), $id]));
        }

        if (! in_array($this->driver, $set->drivers, true)) {
            throw SchemaSetException::unsupportedDriver($id, $this->driver);
        }

        $this->visiting[$id] = true;
        $dependencies = $set->prerequisiteSets;

        if ($set->lifecycle === SchemaLifecycle::Module && $this->bootstrap !== null && $this->bootstrap->id() !== $id
            && $this->bootstrap->scope === $set->scope && $this->bootstrap->connection === $set->connection) {
            $dependencies[] = $this->bootstrap->id();
        }

        $level = 0;
        foreach ($dependencies as $dependency) {
            $required = $this->sets->get($dependency);

            if ($required->scope !== $set->scope || $required->connection !== $set->connection) {
                throw SchemaSetException::invalidPrerequisite($id, "[$dependency] belongs to another scope or connection");
            }

            if ($set->lifecycle === SchemaLifecycle::Platform && $required->lifecycle === SchemaLifecycle::Module) {
                throw SchemaSetException::invalidPrerequisite($id, "platform migration would activate module [$dependency]");
            }
            $level = max($level, 1 + $this->level($required));
        }

        unset($this->visiting[$id]);
        $this->batches[$level][$id] = $set;

        return $this->levels[$id] = $level;
    }
}
