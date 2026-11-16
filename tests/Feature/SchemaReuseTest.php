<?php

declare(strict_types=1);

namespace Packman\Testkit\Tests\Feature;

use Override;
use Packman\PackmanServiceProvider;
use Packman\Schema\Contracts\Migration\SchemaMigrator;
use Packman\Schema\Contracts\Migration\SchemaSetProvider;
use Packman\Schema\Contracts\Migration\SchemaSets;
use Packman\Schema\Data\Migration\SchemaSet;
use Packman\Schema\Enums\SchemaScope;
use Packman\Schema\Internal\Migration\TaggedSchemaSets;
use Packman\Schema\Laravel\SchemaServiceProvider;
use Packman\Testkit\PackageTestCase;
use Packman\Testkit\Schema\Contracts\SchemaReuseVerifier;

final class SchemaReuseTest extends PackageTestCase
{
    #[Override]
    protected function getPackageProviders($app): array
    {
        return [
            PackmanServiceProvider::class,
            SchemaServiceProvider::class,
        ];
    }

    public function test_schema_reuse_verifier_enforces_idempotent_migrations(): void
    {
        $path = realpath(__DIR__.'/../Fixtures/migrations');
        $this->assertIsString($path);

        $provider = new class($path) implements SchemaSetProvider
        {
            public function __construct(private string $path) {}

            public function sets(): array
            {
                return [
                    new SchemaSet('testkit/fixture', 'central', SchemaScope::Central, $this->path, 1, ['pgsql', 'sqlite']),
                ];
            }
        };

        $this->app->instance(SchemaSets::class, new TaggedSchemaSets([$provider]));
        $this->app->forgetInstance(SchemaMigrator::class);

        /** @var SchemaMigrator $migrator */
        $migrator = $this->app->make(SchemaMigrator::class);
        $verifier = new SchemaReuseVerifier($migrator);
        $verifier->assertIdempotent(SchemaScope::Central, 'sqlite');
    }
}
