<?php

declare(strict_types=1);

namespace Packman\Modules\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** Packages register their Eloquent casts without coupling the kernel to them. */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface FieldCastRegistry
{
    /**
     * @param  class-string  $castClass
     * @param  'string'|'int'|'decimal'|'bool'|'date'|'datetime'|'json'|'money'|'relation'|'enum'  $type
     */
    public function register(string $castClass, string $type): void;

    /** @return 'string'|'int'|'decimal'|'bool'|'date'|'datetime'|'json'|'money'|'relation'|'enum'|null */
    public function typeFor(string $cast): ?string;
}
