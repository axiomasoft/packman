<?php

declare(strict_types=1);

namespace Packman\Testkit\Schema\Contracts;

use Packman\Attributes\Api;
use Packman\Schema\Contracts\Migration\SchemaMigrator;
use Packman\Schema\Enums\SchemaScope;
use PHPUnit\Framework\Assert;

/** Verifies SchemaMigrator sets are idempotent across repeated migrations with zero pending drift. */
#[Api(since: '1.0')]
final readonly class SchemaReuseVerifier
{
    public function __construct(private SchemaMigrator $migrator) {}

    public function assertIdempotent(SchemaScope $scope, string $connection): void
    {
        $this->migrator->migrate($scope, $connection);
        $secondPass = $this->migrator->migrate($scope, $connection);
        Assert::assertSame([], $secondPass, "Expected second {$scope->value} migration pass on [{$connection}] to be a no-op.");

        foreach ($this->migrator->status($scope, $connection) as $status) {
            Assert::assertSame([], $status->pending, "Expected zero pending migrations for {$status->set->id()}.");
            Assert::assertSame([], $status->unknown, "Expected zero unknown migrations for {$status->set->id()}.");
        }
    }
}
