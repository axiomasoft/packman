<?php

declare(strict_types=1);

namespace Packman\Schema\Internal\Commands;

use Illuminate\Console\Command;
use Packman\Attributes\Internal;
use Packman\Schema\Contracts\Migration\SchemaMigrator;
use Packman\Schema\Enums\SchemaScope;

/** @internal */
#[Internal]
final class SchemaStatusCommand extends Command
{
    protected $signature = 'packman:schema:status {--scope=tenant : central|tenant|dedicated} {--database=}';

    protected $description = 'Show the registered Packman schema sets and their migration status.';

    public function handle(SchemaMigrator $migrator): int
    {
        $rawScope = $this->option('scope');
        $scope = is_string($rawScope) ? SchemaScope::tryFrom($rawScope) : null;

        if ($scope === null) {
            $this->error('Unknown scope.');

            return self::INVALID;
        }
        $rows = [];
        $pending = false;
        foreach ($migrator->status(scope: $scope, connection: $this->connectionName()) as $status) {
            $rows[] = [$status->set->id(), $status->set->scope->value, $status->set->lifecycle->value, $status->set->version, count($status->applied), count($status->pending), count($status->unknown)];
            $pending = $pending || $status->pending !== [] || $status->unknown !== [];
        }
        $this->table(['set', 'scope', 'lifecycle', 'version', 'applied', 'pending', 'unknown'], $rows);

        return $pending ? self::FAILURE : self::SUCCESS;
    }

    private function connectionName(): ?string
    {
        $database = $this->option('database');

        if (! is_string($database)) {
            return null;
        }

        $name = trim($database);

        return $name === '' ? null : $name;
    }
}
