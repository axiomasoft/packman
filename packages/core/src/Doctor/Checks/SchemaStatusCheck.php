<?php

declare(strict_types=1);

namespace Packman\Doctor\Checks;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Migrations\MigrationRepositoryInterface;
use Packman\Attributes\Internal;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Data\DoctorFinding;
use Throwable;

#[Internal]
final readonly class SchemaStatusCheck implements DoctorCheck
{
    public function __construct(private Container $container) {}

    public function key(): string
    {
        return 'core.schema.status';
    }

    public function title(): string
    {
        return 'Database connection and migrations';
    }

    public function run(DoctorContext $context): array
    {
        if (! $this->container->bound(ConnectionResolverInterface::class)) {
            return [
                DoctorFinding::skipped(
                    code: 'core.schema.skipped',
                    message: 'Database connection resolver is not bound in this environment.',
                ),
            ];
        }

        try {
            $resolver = $this->container->make(ConnectionResolverInterface::class);
            $connection = $resolver->connection();
            $connection->select('select 1 as ok');
        } catch (Throwable) {
            return [
                DoctorFinding::error(
                    code: 'core.database.connection_failed',
                    message: 'The default database connection does not answer.',
                    remediation: 'Verify config/database.php and confirm the database server is reachable.',
                ),
            ];
        }

        try {
            if (! $this->container->bound('migration.repository')) {
                return [
                    DoctorFinding::ok(
                        code: 'core.database.connected',
                        message: 'Database connection succeeded (migration repository not configured).',
                    ),
                ];
            }

            $repository = $this->container->make('migration.repository');

            if (! $repository->repositoryExists()) {
                return [
                    DoctorFinding::at(
                        status: $context->strictStatus(),
                        severity: $context->strictSeverity(),
                        code: 'core.schema.migrations_table_missing',
                        message: 'The migrations table does not exist.',
                        remediation: 'Run php artisan migrate.',
                    ),
                ];
            }

            $pending = $this->pendingMigrations($repository);
        } catch (Throwable) {
            return [
                DoctorFinding::error(
                    code: 'core.schema.inspection_failed',
                    message: 'The migration repository could not be read.',
                    remediation: 'Verify the migrations table and the database user privileges.',
                ),
            ];
        }

        if ($pending === []) {
            return [
                DoctorFinding::ok(
                    code: 'core.schema.migrations_applied',
                    message: 'Every registered migration is applied.',
                ),
            ];
        }

        return [
            DoctorFinding::at(
                status: $context->strictStatus(),
                severity: $context->strictSeverity(),
                code: 'core.schema.migrations_pending',
                message: count($pending).' migration(s) are pending.',
                remediation: 'Run php artisan migrate.',
                evidence: ['first' => $pending[0], 'count' => count($pending)],
            ),
        ];
    }

    /** @return list<string> */
    private function pendingMigrations(MigrationRepositoryInterface $repository): array
    {
        $applied = array_fill_keys($repository->getRan(), true);
        $paths = $this->container->bound('migrator')
            ? $this->container->make('migrator')->paths()
            : [];

        if ($this->container->bound('path.database')) {
            $databasePath = $this->container->make('path.database');

            if (is_string($databasePath) && $databasePath !== '') {
                $paths[] = rtrim($databasePath, '/').'/migrations';
            }
        }

        $files = [];
        foreach ($paths as $directory) {
            if (is_file($directory) && str_ends_with($directory, '.php')) {
                $files[] = $directory;

                continue;
            }

            if (! is_dir($directory)) {
                continue;
            }

            $matched = glob(rtrim($directory, '/').'/*.php');

            if (is_array($matched)) {
                array_push($files, ...$matched);
            }
        }

        $pending = [];
        foreach ($files as $file) {
            $name = basename($file, '.php');

            if (! isset($applied[$name])) {
                $pending[] = $name;
            }
        }

        sort($pending, SORT_STRING);

        return $pending;
    }
}
