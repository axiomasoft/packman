<?php

declare(strict_types=1);

namespace Packman\Plugins\Enums\Contributions;

use Packman\Attributes\Api;

/**
 * How many active providers a capability, or contributions a slot, admits.
 */
#[Api(since: '1.0')]
enum Cardinality: string
{
    case RequiredSingle = 'required-single';
    case OptionalSingle = 'optional-single';
    case Many = 'many';

    public function admits(int $count): bool
    {
        return match ($this) {
            self::RequiredSingle => $count === 1,
            self::OptionalSingle => $count <= 1,
            self::Many => true,
        };
    }
}
