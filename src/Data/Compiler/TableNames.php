<?php

declare(strict_types=1);

namespace Packman\Schema\Data\Compiler;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use LogicException;
use Packman\Attributes\Api;
use Packman\Config\Data\TablePrefix;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Schema\Contracts\Compiler\SchemaOwnership;

/**
 * Resolves logical table names to physical prefixed table names and verifies the database install fingerprint on first lookup.
 */
#[Api(since: '1.0')]
final class TableNames
{
    private bool $verified = false;

    private readonly TablePrefix $tablePrefix;

    /** @param  (Closure(ConnectionInterface, string): bool)|null  $ownsLegacy */
    public function __construct(
        TablePrefix|string $prefix,
        private readonly SchemaOwnership $ownership,
        private readonly ConnectionResolverInterface $connections,
        private readonly ?string $connection = null,
        private readonly string $configPath = 'packman.schema.table_prefix',
        private readonly ?string $owner = null,
        private readonly ?Closure $ownsLegacy = null,
    ) {
        $this->tablePrefix = $prefix instanceof TablePrefix
            ? $prefix
            : TablePrefix::fromArrayValue($prefix, $this->configPath);
    }

    /**
     * @throws InvalidArgumentException for a malformed logical name
     * @throws LogicException when no package declared the table's noun
     * @throws InvalidConfiguration when the configured prefix differs from the installed one
     */
    public function table(string $logical): string
    {
        if (preg_match('/\A[a-z][a-z0-9]*(_[a-z0-9]+)*\z/', $logical) !== 1) {
            throw new InvalidArgumentException("Logical table '{$logical}' must be a lower-case snake name.");
        }

        $physical = $this->tablePrefix->table($logical);

        if (strlen($physical) > ConstraintNames::MAX_IDENTIFIER) {
            throw new InvalidArgumentException("Table name '{$physical}' is longer than 63 bytes.");
        }

        if ($this->ownership->ownerOf($logical) === null) {
            throw new LogicException("Table '{$logical}' has no owner: declare its noun through SchemaOwnership.");
        }

        if (! $this->verified) {
            SchemaFingerprint::verify(
                $this->connections->connection($this->connection),
                $this->tablePrefix->value(),
                $this->configPath,
                $this->owner,
                $this->ownsLegacy,
            );
            $this->verified = true;
        }

        return $physical;
    }

    public function connection(): ?string
    {
        return $this->connection;
    }

    public function prefix(): string
    {
        return $this->tablePrefix->value();
    }
}
