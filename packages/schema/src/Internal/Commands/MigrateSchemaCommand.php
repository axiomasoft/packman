<?php

declare(strict_types=1);

namespace Packman\Schema\Internal\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Packman\Attributes\Internal;
use Packman\Schema\Contracts\Migration\SchemaMigrator;
use Packman\Schema\Enums\SchemaScope;

/** @internal */
#[Internal]
final class MigrateSchemaCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'packman:schema:migrate {--scope=tenant : central|tenant|dedicated} {--database=} {--modules} {--force}';

    protected $description = 'Run the registered Packman schema sets of one scope.';

    public function handle(SchemaMigrator $migrator): int
    {
        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }
        $rawScope = $this->option('scope');
        $scope = is_string($rawScope) ? SchemaScope::tryFrom($rawScope) : null;

        if ($scope === null) {
            $this->error('Unknown scope.');

            return self::INVALID;
        }
        $ran = $migrator->migrate(scope: $scope, connection: $this->connectionName(), includeModules: (bool) $this->option('modules'));
        $this->line($ran === [] ? 'Nothing to migrate.' : implode(PHP_EOL, $ran));

        return self::SUCCESS;
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
