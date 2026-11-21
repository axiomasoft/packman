<?php

declare(strict_types=1);

use Packman\Panels\SavedViews\Data\ViewField;
use Packman\Panels\SavedViews\Data\ViewSchema;
use Packman\Panels\SavedViews\Internal\ViewConfigValidator;

it('rejects an unsafe saved-view filter before a consumer can map it to SQL', function (): void {
    $validator = new ViewConfigValidator;
    $schema = new ViewSchema('neutral/1', 'lab.sample', [
        new ViewField('name', 'string', ['eq', 'ne', 'in', 'is_null'], true, true, true, true, 'name'),
    ]);

    expect(fn (): array => $validator->validate([
        'version' => 'saved-view/1',
        'filters' => ['field' => 'name', 'op' => 'raw_sql', 'value' => 'select 1'],
        'sorts' => [],
        'columns' => ['name'],
        'groupBy' => null,
        'presentation' => [],
    ], $schema))->toThrow(InvalidArgumentException::class);
});

it('rejects malformed field keys with the configuration error before indexing the schema', function (array $config): void {
    $schema = new ViewSchema('neutral/1', 'lab.sample', [
        new ViewField('name', 'string', ['eq'], true, true, true, true, 'name'),
    ]);

    expect(fn (): array => (new ViewConfigValidator)->validate(['version' => 'saved-view/1', ...$config], $schema))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'projection' => [['columns' => [['name']]]],
    'sort' => [['sorts' => [['field' => ['name'], 'direction' => 'asc']]]],
    'filter' => [['filters' => ['field' => ['name'], 'op' => 'eq', 'value' => 'Ada']]],
    'numeric filter property' => [['filters' => ['field' => 'name', 'op' => 'eq', 'value' => 'Ada', 10 => 'extra']]],
    'numeric child property' => [['filters' => ['op' => 'and', 'args' => [['field' => 'name', 'op' => 'eq', 'value' => 'Ada', 10 => 'extra']]]]],
]);

it('preserves supported sort data while validating the field and direction', function (): void {
    $schema = new ViewSchema('neutral/1', 'lab.sample', [
        new ViewField('name', 'string', ['eq'], true, true, true, true, 'name'),
    ]);
    $sort = ['field' => 'name', 'direction' => 'asc', 'label' => 'Display name'];
    $validated = (new ViewConfigValidator)->validate(['version' => 'saved-view/1', 'sorts' => [$sort]], $schema);

    expect($validated['sorts'])->toBe([$sort]);

    $hidden = new ViewSchema('neutral/1', 'lab.sample', [
        new ViewField('secret', 'string', ['eq'], false, true, true, true, 'secret'),
    ]);

    expect(fn (): array => (new ViewConfigValidator)->validate([
        'version' => 'saved-view/1',
        'groupBy' => 'secret',
    ], $hidden))->toThrow(InvalidArgumentException::class, 'group');
});
