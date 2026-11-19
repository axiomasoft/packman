<?php

declare(strict_types=1);

namespace Packman\Modules\Internal\Compiler;

use Packman\Attributes\Internal;
use Packman\Modules\Contracts\Compiler\FieldCastRegistry;

/** @internal Mutable composition registry. */
#[Internal]
final class FieldCastMap implements FieldCastRegistry
{
    /** @var array<class-string, 'string'|'int'|'decimal'|'bool'|'date'|'datetime'|'json'|'money'|'relation'|'enum'> */
    private array $types = [];

    public function register(string $castClass, string $type): void
    {
        $this->types[$castClass] = $type;
    }

    public function typeFor(string $cast): ?string
    {
        $class = strstr($cast, ':', true);

        return $this->types[$class === false ? $cast : $class] ?? null;
    }
}
