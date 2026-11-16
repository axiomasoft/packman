<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Packman\Modules\Data\EntityDefinition;
use Packman\Modules\Enums\Capability;
use Packman\Modules\Exceptions\RegistryFrozen;
use Packman\Modules\Testing\ArrayEntityRegistry;

function freezeDefinition(string $handle): EntityDefinition
{
    return new EntityDefinition(
        handle: $handle,
        model: Model::class,
        table: 'freeze_things',
        module: 'example/freeze',
        labelSingular: 'Thing',
        labelPlural: 'Things',
        capabilities: [Capability::Auditable],
        fields: [],
        titleAttribute: null,
    );
}

it('rejects duplicate registration before mutating the collector', function (): void {
    $registry = new ArrayEntityRegistry;
    $first = freezeDefinition('freeze.thing');
    $registry->register($first);

    try {
        $registry->register(freezeDefinition('freeze.thing'));
        expect(false)->toBeTrue();
    } catch (RegistryFrozen $exception) {
        expect($exception->getMessage())->toContain('Duplicate [entity:freeze.thing]')
            ->and($registry->get('freeze.thing'))->toBe($first);
    }
});

it('rejects late registration and leaves collector and morph map unchanged', function (): void {
    $registry = new ArrayEntityRegistry;
    $registry->register(freezeDefinition('freeze.thing'));
    Relation::morphMap(['freeze.thing' => Model::class]);
    $beforeMap = Relation::morphMap();
    $registry->freeze();

    try {
        $registry->register(freezeDefinition('freeze.late'));
        expect(false)->toBeTrue();
    } catch (RegistryFrozen $exception) {
        expect($exception->getMessage())->toContain('after the entity collector is frozen')
            ->and($registry->has('freeze.late'))->toBeFalse()
            ->and(array_keys($registry->all()))->toBe([0])
            ->and($registry->get('freeze.thing')->handle)->toBe('freeze.thing')
            ->and(Relation::morphMap())->toBe($beforeMap);
    }
});
