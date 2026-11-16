<?php

declare(strict_types=1);

namespace Packman\Schema\Contracts\Compiler;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Schema\Data\Compiler\SchemaSpace;

/**
 * The schema a package contributes to every space it applies to: an append-only, ordered list of immutable steps.
 *
 * Step ids are `YYYY_MM_DD_HHMMSS_<snake_name>`. The timestamp orders the generated migration files, so a step that
 * needs objects of another component carries a later timestamp than the step that creates them. A released step is
 * never edited, reordered or removed — the schema changes only by appending a new step.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface SchemaComponent
{
    /** Stable id, `^[a-z][a-z0-9_]{0,23}$`: the package slug, e.g. 'core'. Part of every migration name — never renamed. */
    public function id(): string;

    /** @return list<string> logical table names created by this component, `^[a-z][a-z0-9_]{0,39}$` */
    public function tables(): array;

    /** @return array<string, class-string<SchemaStep>> step id => step class, in execution order */
    public function steps(): array;

    /** @return list<string> ids of components whose tables this component references */
    public function dependsOn(): array;

    /**
     * Whether the component's tables exist in this space. Decided at compile time; must be a pure function of the
     * given space (never of runtime state), because the answer selects migration files.
     */
    public function appliesTo(SchemaSpace $space): bool;
}
