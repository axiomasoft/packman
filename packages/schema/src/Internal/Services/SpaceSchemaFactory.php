<?php

declare(strict_types=1);

namespace Packman\Schema\Internal\Services;

use Closure;
use Packman\Attributes\Internal;
use Packman\Schema\Data\Compiler\SchemaSpace;
use Packman\Schema\Internal\Contracts\SchemaWriter;
use Packman\Schema\SpaceSchema;

/** Builds SpaceSchema instances while keeping the private constructor out of the public API. */
#[Internal]
final class SpaceSchemaFactory
{
    /**
     * @param  list<string>  $owned  logical tables of the running component
     * @param  list<string>  $referenceable  owned + tables of the component's dependencies
     * @param  array<string, string>  $owners  logical table => id of the component that declares it
     * @param  string  $step  id of the running step
     */
    public static function make(SchemaSpace $space, string $component, array $owned, array $referenceable, SchemaWriter $writer, array $owners = [], string $step = ''): SpaceSchema
    {
        return Closure::bind(
            static fn (): SpaceSchema => new SpaceSchema($space, $component, $owned, $referenceable, $writer, $owners, $step),
            null,
            SpaceSchema::class,
        )();
    }
}
