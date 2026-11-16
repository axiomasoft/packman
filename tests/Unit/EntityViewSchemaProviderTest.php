<?php

declare(strict_types=1);

use Packman\Context\Data\ScopeRef;
use Packman\Modules\Contracts\Entities\EntityRegistry;
use Packman\Modules\Data\Entities\EntityDefinition;
use Packman\Modules\Data\Entities\FieldDefinition;
use Packman\Panels\Internal\Subjects\EntityViewSchemaProvider;
use Packman\Panels\SavedViews\Data\ViewContext;
use Packman\Panels\SavedViews\Data\ViewSchema;

function savedViewContext(): ViewContext
{
    return new ViewContext(
        tenant: new ScopeRef('neutral-account'),
        actorKind: 'user',
        actorId: 'neutral-user',
    );
}

it('maps only a current active entity registry entry into a neutral view schema', function (): void {
    $entity = new EntityDefinition(
        handle: 'sample.note',
        model: stdClass::class,
        table: 'sample_notes',
        module: 'sample/notes',
        labelSingular: 'Note',
        labelPlural: 'Notes',
        capabilities: [],
        fields: [new FieldDefinition(handle: 'title', type: 'string')],
        titleAttribute: 'title',
    );

    $registry = Mockery::mock(EntityRegistry::class);
    $registry->shouldReceive('has')->once()->with('sample.note')->andReturnTrue();
    $registry->shouldReceive('get')->twice()->with('sample.note')->andReturn($entity);
    $registry->shouldReceive('all')->once()->with(Mockery::type(ScopeRef::class))->andReturn([$entity]);

    $schema = (new EntityViewSchemaProvider($registry))->schema(savedViewContext(), 'sample.note');

    expect($schema->version)->toBe('modules/1')
        ->and($schema->entityHandle)->toBe('sample.note')
        ->and($schema->fields[0]->key)->toBe('title')
        ->and($schema->fields[0]->operators)->toBe(['eq', 'ne', 'in', 'is_null']);
});

it('rejects an unknown or inactive entity instead of retaining a stale schema', function (): void {
    $entity = new EntityDefinition(
        handle: 'sample.note',
        model: stdClass::class,
        table: 'sample_notes',
        module: 'sample/notes',
        labelSingular: 'Note',
        labelPlural: 'Notes',
        capabilities: [],
        fields: [],
        titleAttribute: null,
    );

    $registry = Mockery::mock(EntityRegistry::class);
    $registry->shouldReceive('has')->once()->with('sample.note')->andReturnTrue();
    $registry->shouldReceive('get')->once()->with('sample.note')->andReturn($entity);
    $registry->shouldReceive('all')->once()->with(Mockery::type(ScopeRef::class))->andReturn([]);

    expect(fn (): ViewSchema => (new EntityViewSchemaProvider($registry))->schema(savedViewContext(), 'sample.note'))
        ->toThrow(LogicException::class, 'Saved-view entity schema is unavailable.');
});
