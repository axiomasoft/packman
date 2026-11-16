<?php

declare(strict_types=1);

namespace Packman\Schema\Tests\Unit;

use Packman\Schema\Data\SchemaSet;
use Packman\Schema\Enums\SchemaLifecycle;
use Packman\Schema\Enums\SchemaScope;
use Packman\Schema\Exceptions\SchemaSetException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SchemaSetBoundaryTest extends TestCase
{
    /** @param array<string, mixed> $override */
    #[DataProvider('invalidDefinitions')]
    public function test_invalid_definition_is_rejected_without_booting_a_database(array $override): void
    {
        $definition = array_replace([
            'package' => 'fixture/package',
            'name' => 'tenant',
            'scope' => SchemaScope::Tenant,
            'path' => '/schema',
            'version' => 1,
        ], $override);
        $this->expectException(SchemaSetException::class);
        $this->expectExceptionMessage('invalid_set: Invalid schema set ['.$definition['package'].':'.$definition['name'].'].');
        new SchemaSet(...$definition);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidDefinitions(): iterable
    {
        yield 'package prefix' => [['package' => '!fixture/package']];
        yield 'package suffix' => [['package' => 'fixture/package!']];
        yield 'package separator' => [['package' => 'fixture']];
        yield 'name prefix' => [['name' => '!tenant']];
        yield 'name suffix' => [['name' => 'tenant!']];
        yield 'empty path' => [['path' => '']];
        yield 'relative path' => [['path' => 'schema']];
        yield 'embedded drive prefix' => [['path' => 'prefixC:/schema']];
        yield 'version zero' => [['version' => 0]];
        yield 'version negative' => [['version' => -1]];
        yield 'no PostgreSQL' => [['drivers' => ['sqlite']]];
        yield 'case sensitive driver' => [['drivers' => ['PGSQL']]];
        yield 'empty connection' => [['connection' => '']];
        yield 'dedicated missing connection' => [['scope' => SchemaScope::Dedicated]];
        yield 'tenant with connection' => [['connection' => 'isolated']];
    }

    public function test_absolute_path_forms_and_dedicated_connection_preserve_the_metadata_contract(): void
    {
        foreach (['/schema', '\\\\server\\schema', 'C:\\schema', 'C:/schema'] as $path) {
            $set = new SchemaSet(package: 'fixture/package', name: 'tenant', scope: SchemaScope::Tenant, path: $path, version: 1);
            self::assertSame('fixture/package:tenant', $set->id());
            self::assertSame($path, $set->path);
            self::assertSame(['pgsql'], $set->drivers);
            self::assertSame([], $set->requires);
            self::assertSame(SchemaLifecycle::Platform, $set->lifecycle);
            self::assertNull($set->connection);
        }
        $set = new SchemaSet(package: 'fixture/package', name: 'dedicated', scope: SchemaScope::Dedicated, path: '/schema', version: 1, drivers: ['pgsql', 'sqlite'], connection: 'isolated');
        self::assertSame('isolated', $set->connection);
        self::assertSame(['pgsql', 'sqlite'], $set->drivers);
    }
}
