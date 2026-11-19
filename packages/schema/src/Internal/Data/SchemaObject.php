<?php

declare(strict_types=1);

namespace Packman\Schema\Internal\Data;

use Packman\Attributes\Internal;

/**
 * A database object Blueprint cannot express: a named CHECK constraint, or a driver-specific function/trigger.
 */
#[Internal]
final readonly class SchemaObject
{
    public const string CHECK = 'check';

    public const string FUNCTION = 'function';

    public const string TRIGGER = 'trigger';

    public function __construct(
        public string $kind,
        public string $name,
        public string $table,
        public string $expression = '',
        public string $up = '',
        public string $down = '',
    ) {}
}
