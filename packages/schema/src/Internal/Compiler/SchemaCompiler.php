<?php

declare(strict_types=1);

namespace Packman\Schema\Internal\Compiler;

use Packman\Attributes\Internal;
use Packman\Schema\Contracts\Compiler\RetiresSteps;
use Packman\Schema\Contracts\Compiler\SchemaComponent;
use Packman\Schema\Contracts\Compiler\SchemaStep;
use Packman\Schema\Data\Compiler\SchemaSpace;
use Packman\Schema\Exceptions\InvalidSchemaException;
use Packman\Schema\Internal\Data\CompiledSchema;
use Packman\Schema\Internal\Services\ConnectionIdentity;
use Packman\Schema\Internal\Services\SchemaNames;
use Packman\Schema\Internal\Services\StorageCollisionDetector;

/**
 * Compiles schema components against schema spaces. Pure: the caller passes component instances, the spaces, and
 * the identity of every connection they use. Reports every problem at once.
 */
#[Internal]
final class SchemaCompiler
{
    /**
     * @param  list<SchemaComponent>  $components
     * @param  list<SchemaSpace>  $spaces
     * @param  array<string, string>  $identities  connection name => ConnectionIdentity::of()
     */
    public function compile(array $components, array $spaces, array $identities): CompiledSchema
    {
        $problems = [];
        $declared = [];
        $tableOwner = [];

        foreach ($components as $component) {
            $id = $component->id();

            if (preg_match(SchemaNames::COMPONENT_ID, $id) !== 1) {
                $problems[] = "component id [{$id}] must match ".SchemaNames::COMPONENT_ID;

                continue;
            }

            if (isset($declared[$id])) {
                $problems[] = "component [{$id}] is registered twice";

                continue;
            }

            $tables = $component->tables();

            foreach ($tables as $table) {
                if (preg_match(SchemaNames::LOGICAL_TABLE, $table) !== 1) {
                    $problems[] = "component [{$id}]: logical table [{$table}] must match ".SchemaNames::LOGICAL_TABLE;
                } elseif (isset($tableOwner[$table])) {
                    $problems[] = "logical table [{$table}] is declared by [{$tableOwner[$table]}] and [{$id}]";
                } else {
                    $tableOwner[$table] = $id;
                }
            }

            $steps = $component->steps();
            $problems = [...$problems, ...$this->stepProblems($id, $steps)];

            $declared[$id] = [
                'tables' => $tables,
                'depends_on' => array_values(array_unique($component->dependsOn())),
                'steps' => $steps,
                'retired' => $component instanceof RetiresSteps ? $component->retiredSteps() : [],
                'instance' => $component,
            ];
        }

        foreach ($declared as $id => $component) {
            foreach ($component['depends_on'] as $dependency) {
                if (! isset($declared[$dependency])) {
                    $problems[] = "component [{$id}] depends on unknown component [{$dependency}]";
                } elseif ($component['steps'] !== [] && $declared[$dependency]['steps'] !== []) {
                    $problems = [...$problems, ...$this->orderProblems($id, $component['steps'], $dependency, $declared[$dependency]['steps'])];
                }
            }
        }

        [$order, $cycle] = $this->order($declared);

        if ($cycle !== []) {
            $problems[] = 'components depend on each other in a cycle: ['.implode(', ', $cycle).']';
        }

        $schemaSpaces = [];
        $applicable = [];
        $slugs = [];
        $claims = [];

        foreach ($spaces as $space) {
            $slug = SchemaNames::spaceSlug($space->id);

            if ($slug === '' || strlen($slug) > 32) {
                $problems[] = "space [{$space->id}]: migration slug [{$slug}] must be 1..32 characters";
            } elseif (isset($slugs[$slug])) {
                $problems[] = "spaces [{$slugs[$slug]}] and [{$space->id}] share the migration slug [{$slug}]";
            } else {
                $slugs[$slug] = $space->id;
            }

            if ($space->isDefault !== ($space->id === 'default')) {
                $problems[] = "space [{$space->id}]: only the space with id [default] is the default space";
            }

            $identity = $identities[$space->connection] ?? null;

            if ($identity === null) {
                $problems[] = "space [{$space->id}]: connection [{$space->connection}] is not configured";

                continue;
            }

            if (! ConnectionIdentity::isSupported($identity)) {
                $problems[] = "space [{$space->id}]: connection [{$space->connection}] uses an unsupported driver (pgsql, mysql, sqlite)";

                continue;
            }

            $schemaSpaces[$space->id] = $space;
            $applicable[$space->id] = [];

            foreach ($order as $componentId) {
                if (! $declared[$componentId]['instance']->appliesTo($space)) {
                    continue;
                }

                $applicable[$space->id][] = $componentId;

                foreach ($declared[$componentId]['tables'] as $logical) {
                    $physical = $space->databaseTable($logical);

                    if (strlen($physical) > SchemaNames::MAX_IDENTIFIER || preg_match(SchemaNames::IDENTIFIER, $physical) !== 1) {
                        $problems[] = "space [{$space->id}]: table [{$physical}] must be an identifier of at most 63 bytes";
                    }

                    $claims[] = ['identity' => $identity, 'table' => $physical, 'owner' => "{$space->id}/{$componentId}"];
                }
            }

            foreach ($applicable[$space->id] as $componentId) {
                foreach ($declared[$componentId]['depends_on'] as $dependency) {
                    if (isset($declared[$dependency]) && ! in_array($dependency, $applicable[$space->id], true)) {
                        $problems[] = "space [{$space->id}]: component [{$componentId}] applies but its dependency [{$dependency}] does not";
                    }
                }
            }
        }

        $problems = [...$problems, ...StorageCollisionDetector::problems($claims)];

        if ($problems !== []) {
            throw InvalidSchemaException::fromProblems($problems);
        }

        $compiled = [];

        foreach ($order as $id) {
            $compiled[$id] = [
                'tables' => $declared[$id]['tables'],
                'depends_on' => $declared[$id]['depends_on'],
                'steps' => $declared[$id]['steps'],
                'retired' => $declared[$id]['retired'],
            ];
        }

        ksort($schemaSpaces);
        ksort($applicable);

        return new CompiledSchema($compiled, $schemaSpaces, $applicable);
    }

    /**
     * @param  array<string, mixed>  $steps
     * @return list<string>
     */
    private function stepProblems(string $component, array $steps): array
    {
        $problems = [];
        $previous = null;
        $classes = [];

        if ($steps === []) {
            return ["component [{$component}] declares no steps"];
        }

        foreach ($steps as $stepId => $class) {
            if (preg_match(SchemaNames::STEP_ID, $stepId, $m) !== 1) {
                $problems[] = "component [{$component}]: step id [{$stepId}] must be YYYY_MM_DD_HHMMSS_<snake_name>";

                continue;
            }

            if ($previous !== null && strcmp($m[1], $previous) <= 0) {
                $problems[] = "component [{$component}]: step [{$stepId}] must be later than the step before it";
            }

            $previous = $m[1];

            if (! is_string($class) || ! is_a($class, SchemaStep::class, true)) {
                $problems[] = "component [{$component}]: step [{$stepId}] class must implement ".SchemaStep::class;
            } elseif (isset($classes[$class])) {
                $problems[] = "component [{$component}]: class [{$class}] is used by two steps";
            } else {
                $classes[$class] = true;
            }
        }

        return $problems;
    }

    /**
     * @param  array<string, class-string<SchemaStep>>  $steps
     * @param  array<string, class-string<SchemaStep>>  $dependencySteps
     * @return list<string>
     */
    private function orderProblems(string $component, array $steps, string $dependency, array $dependencySteps): array
    {
        $at = static fn (array $list): array => array_map(static fn (string $id): string => substr($id, 0, 17), array_keys($list));
        $mine = $at($steps);
        $theirs = $at($dependencySteps);
        $problems = [];

        if (strcmp($mine[0], $theirs[0]) <= 0) {
            $problems[] = "component [{$component}]: first step must be later than the first step of its dependency [{$dependency}]";
        }

        foreach (array_intersect($mine, $theirs) as $shared) {
            $problems[] = "components [{$component}] and [{$dependency}] both have a step at [{$shared}]";
        }

        return $problems;
    }

    /**
     * Kahn's algorithm; among ready components the smallest id goes first.
     *
     * @param  array<string, array{depends_on: list<string>, tables: list<string>, steps: array<string, class-string<SchemaStep>>, retired: array<string, string>, instance: SchemaComponent}>  $components
     * @return array{0: list<string>, 1: list<string>}
     */
    private function order(array $components): array
    {
        $pending = [];

        foreach ($components as $id => $component) {
            $pending[$id] = array_values(array_filter($component['depends_on'], static fn (string $d): bool => isset($components[$d])));
        }

        $order = [];

        while ($pending !== []) {
            $ready = array_keys(array_filter($pending, static fn (array $deps): bool => $deps === []));

            if ($ready === []) {
                $left = array_keys($pending);
                sort($left);

                return [$order, $left];
            }

            sort($ready);
            $next = $ready[0];
            $order[] = $next;
            unset($pending[$next]);

            foreach ($pending as $id => $deps) {
                $pending[$id] = array_values(array_diff($deps, [$next]));
            }
        }

        return [$order, []];
    }
}
