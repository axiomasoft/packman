<?php

declare(strict_types=1);

namespace Packman\Schema\Internal\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Packman\Schema\Contracts\SchemaMigrator;
use Packman\Schema\Enums\SchemaScope;

/** @internal */
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
        $database = $this->option('database');
        $ran = $migrator->migrate(scope: $scope, connection: is_string($database) ? $database : null, includeModules: (bool) $this->option('modules'));
        $this->line($ran === [] ? 'Nothing to migrate.' : implode(PHP_EOL, $ran));

        return self::SUCCESS;
    }
}
