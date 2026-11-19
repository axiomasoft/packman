<?php

declare(strict_types=1);

namespace Packman\Schema\Internal\Data;

use Packman\Attributes\Internal;
use Packman\Schema\Contracts\Compiler\SchemaStep;
use Packman\Schema\Data\Compiler\SchemaSpace;
use Packman\Schema\Internal\Services\SchemaNames;

/**
 * Result of SchemaCompiler: plain data so it lives cleanly in cached definitions.
 */
#[Internal]
final readonly class CompiledSchema
{
    /**
     * @param  array<string, array{tables: list<string>, depends_on: list<string>, steps: array<string, class-string<SchemaStep>>, retired?: array<string, string>}>  $components
     * @param  array<string, SchemaSpace>  $spaces
     * @param  array<string, list<string>>  $applicable
     */
    public function __construct(
        public array $components,
        public array $spaces,
        public array $applicable,
    ) {}

    public function space(string $id): ?SchemaSpace
    {
        return $this->spaces[$id] ?? null;
    }

    public function applies(string $space, string $component): bool
    {
        return in_array($component, $this->applicable[$space] ?? [], true);
    }

    /** @return list<string> logical tables a step of this component may reference: its own and its dependencies'. */
    public function referenceable(string $component): array
    {
        $tables = $this->components[$component]['tables'];

        foreach ($this->components[$component]['depends_on'] as $dependency) {
            $tables = [...$tables, ...$this->components[$dependency]['tables']];
        }

        return $tables;
    }

    /** @return array<string, string> logical table => id of the component that declares it */
    public function tableOwners(): array
    {
        $owners = [];

        foreach ($this->components as $id => $component) {
            foreach ($component['tables'] as $table) {
                $owners[$table] = $id;
            }
        }

        return $owners;
    }

    /** @return list<PlannedStep> every expected migration, ordered by migration name (Laravel execution order) */
    public function plan(?string $space = null, string $namespaceSlug = 'packman'): array
    {
        $plan = [];

        foreach ($this->applicable as $spaceId => $componentIds) {
            if ($space !== null && $spaceId !== $space) {
                continue;
            }

            $slug = SchemaNames::spaceSlug($spaceId);

            foreach ($componentIds as $componentId) {
                foreach ($this->components[$componentId]['steps'] as $stepId => $class) {
                    $plan[] = new PlannedStep($spaceId, $componentId, $stepId, $class, SchemaNames::migration($slug, $componentId, $stepId, $namespaceSlug));
                }
            }
        }

        usort($plan, static fn (PlannedStep $a, PlannedStep $b): int => strcmp($a->migration, $b->migration));

        return $plan;
    }

    public function find(string $space, string $component, string $step, string $namespaceSlug = 'packman'): ?PlannedStep
    {
        foreach ($this->plan($space, $namespaceSlug) as $planned) {
            if ($planned->component === $component && $planned->step === $step) {
                return $planned;
            }
        }

        return null;
    }
}
