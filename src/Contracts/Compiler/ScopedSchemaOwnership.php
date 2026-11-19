<?php

declare(strict_types=1);

namespace Packman\Schema\Contracts\Compiler;

use Packman\Attributes\Api;

/** Resolves logical table ownership within the prefix declared by a family of extensions. */
#[Api(since: '0.1.1')]
interface ScopedSchemaOwnership extends SchemaOwnership
{
    public function forPrefix(string $prefix): SchemaOwnership;
}
