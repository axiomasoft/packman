<?php

declare(strict_types=1);

namespace Packman\DevTools\Internal\Gates;

use LogicException;
use Packman\Attributes\Internal;
use PhpParser\Node\Stmt;

/**
 * Deep-clones statement lists. A shallow `clone` would still share subnodes,
 * and NameResolver mutates those in place.
 */
#[Internal]
final class AstCloner
{
    /**
     * @param  list<Stmt>  $statements
     * @return list<Stmt>
     */
    public static function cloneStatements(array $statements): array
    {
        $cloned = unserialize(serialize($statements));

        if (! is_array($cloned)) {
            throw new LogicException('Unable to clone the parsed AST.');
        }

        $copy = [];

        foreach ($cloned as $statement) {
            if (! $statement instanceof Stmt) {
                throw new LogicException('Unable to clone the parsed AST.');
            }

            $copy[] = $statement;
        }

        return $copy;
    }
}
