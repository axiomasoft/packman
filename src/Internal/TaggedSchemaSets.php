<?php

declare(strict_types=1);

namespace Packman\Schema\Internal;

use Packman\Schema\Contracts\SchemaSetProvider;
use Packman\Schema\Contracts\SchemaSets;
use Packman\Schema\Data\SchemaSet;
use Packman\Schema\Enums\SchemaLifecycle;
use Packman\Schema\Enums\SchemaScope;
use Packman\Schema\Exceptions\SchemaSetException;

/** @internal */
final readonly class TaggedSchemaSets implements SchemaSets
{
    /** @var array<string, SchemaSet> */
    private array $sets;

    /** @param iterable<SchemaSetProvider> $providers */
    public function __construct(iterable $providers)
    {
        $declared = [];
        foreach ($providers as $provider) {
            foreach ($provider->sets() as $set) {
                if (isset($declared[$set->id()])) {
                    throw SchemaSetException::duplicateSet($set->id());
                }

                if (! is_dir($set->path)) {
                    throw SchemaSetException::invalidSet($set->id());
                }
                $declared[$set->id()] = $set;
            }
        }

        $packages = array_unique(array_map(static fn (SchemaSet $s): string => $s->package, $declared));
        $active = array_filter($declared, static fn (SchemaSet $s): bool => array_diff($s->requires, $packages) === []);
        ksort($active, SORT_STRING);

        $seen = [];
        foreach ($active as $set) {
            $files = glob($set->path.'/*_*.php');
            foreach ($files === false ? [] : $files as $file) {
                $key = $set->scope->value.'/'.basename($file, '.php');

                if (isset($seen[$key])) {
                    throw SchemaSetException::duplicateMigration(name: basename($file), first: $seen[$key], second: $set->id());
                }
                $seen[$key] = $set->id();
            }
        }

        $this->sets = $active;
    }

    public function all(): array
    {
        return array_values($this->sets);
    }

    public function forScope(SchemaScope $scope, bool $includeModules): array
    {
        return array_values(array_filter($this->sets, static fn (SchemaSet $s): bool => $s->scope === $scope
            && ($includeModules || $s->lifecycle === SchemaLifecycle::Platform)));
    }

    public function get(string $id): SchemaSet
    {
        return $this->sets[$id] ?? throw SchemaSetException::unknownSet($id);
    }

    public function moduleSet(string $package): ?SchemaSet
    {
        foreach ($this->sets as $set) {
            if ($set->package === $package && $set->lifecycle === SchemaLifecycle::Module && $set->scope === SchemaScope::Tenant) {
                return $set;
            }
        }

        return null;
    }
}
