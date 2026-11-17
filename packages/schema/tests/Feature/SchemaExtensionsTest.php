<?php

declare(strict_types=1);

use Packman\Extensions\Concerns\Persistence\OwnsSchema;
use Packman\Extensions\Contracts\Persistence\WithSchema;
use Packman\Schema\Contracts\Compiler\SchemaOwnership;
use Packman\Schema\Contracts\Migration\SchemaSetProvider;
use Packman\Schema\Contracts\Migration\SchemaSets;
use Packman\Schema\Data\Migration\SchemaSet;
use Packman\Schema\Enums\SchemaLifecycle;
use Packman\Schema\Enums\SchemaScope;
use Packman\Schema\Laravel\SchemaExtensions;

final class SampleSchemaExtensionProvider implements SchemaSetProvider
{
    public function sets(): array
    {
        return [
            new SchemaSet(
                package: 'acme/sample-schema',
                name: 'sample-schema',
                scope: SchemaScope::Central,
                path: __DIR__,
                version: 1,
                drivers: ['pgsql', 'sqlite'],
                lifecycle: SchemaLifecycle::Platform,
            ),
        ];
    }
}

final class SampleSchemaExtension implements WithSchema
{
    use OwnsSchema;

    public function tablePrefix(): string
    {
        return 'smp_';
    }

    public function tableNouns(): array
    {
        return ['widget', 'audit_entry'];
    }

    public function schemaSetProviders(): array
    {
        return [SampleSchemaExtensionProvider::class];
    }
}

it('wires WithSchema tableNouns and schemaSetProviders via SchemaExtensions', function (): void {
    SchemaExtensions::register(app(), SampleSchemaExtension::class, 'acme/sample-schema');

    $ownership = app(SchemaOwnership::class);
    $sets = app(SchemaSets::class)->forScope(SchemaScope::Central, includeModules: true);

    expect($ownership->ownerOf('widgets'))->toBe('acme/sample-schema')
        ->and($ownership->ownerOf('audit_entry_items'))->toBe('acme/sample-schema')
        ->and(array_map(static fn (SchemaSet $set): string => $set->name, $sets))
        ->toContain('sample-schema');
});
