<?php

declare(strict_types=1);

namespace Packman\Schema\Contracts\Compiler;

use Packman\Attributes\Api;
use Packman\Config\Exceptions\InvalidConfiguration;

/**
 * Which package owns which table nouns. A logical table belongs to the owner of a noun when it equals the noun, its
 * plural, or starts with "{noun}_" (`stock` owns `stock_levels`, `store` owns `stores`). Third-party plugins use
 * nouns `x_<vendor>`. `TableNames` refuses logical tables without an owner.
 */
#[Api(since: '1.0')]
interface SchemaOwnership
{
    /**
     * @param  string  $owner  package or plugin id, e.g. `packman.schema`
     * @param  list<string>  $nouns  lower-case snake nouns
     *
     * @throws InvalidConfiguration when another owner already declared one of the nouns
     */
    public function declare(string $owner, array $nouns): void;

    /** Owner id of a logical table name (`stock_levels`), or null when no declared noun covers it. */
    public function ownerOf(string $logical): ?string;

    /** @return array<string, list<string>> owner id => nouns, in declaration order */
    public function owners(): array;
}
