<?php

declare(strict_types=1);

namespace Packman\Schema\Internal\Services;

use Packman\Attributes\Internal;

/**
 * Every physical table is owned by exactly one (space, component).
 */
#[Internal]
final class StorageCollisionDetector
{
    /**
     * @param  list<array{identity: string, table: string, owner: string}>  $claims
     * @return list<string>
     */
    public static function problems(array $claims): array
    {
        $owners = [];
        $problems = [];

        foreach ($claims as $claim) {
            $key = $claim['identity']."\0".strtolower($claim['table']);

            if (! isset($owners[$key])) {
                $owners[$key] = $claim['owner'];

                continue;
            }

            $problems[] = "table [{$claim['table']}] on [{$claim['identity']}] is claimed by [{$owners[$key]}] and [{$claim['owner']}]; ".
                'give one space another tablePrefix()/connection or map the table';
        }

        return $problems;
    }
}
