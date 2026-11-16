<?php

declare(strict_types=1);

namespace Packman\Panels\SavedViews\Internal;

use InvalidArgumentException;
use Packman\Attributes\Internal;
use Packman\Panels\SavedViews\Data\ViewField;
use Packman\Panels\SavedViews\Data\ViewSchema;

#[Internal]
final class ViewConfigValidator
{
    /**
     * @param  array<string, mixed>  $config
     * @return array{filters: array<string, mixed>, sorts: list<array{field: string, direction: string}>, columns: list<string>, groupBy: string|null}
     */
    public function validate(array $config, ViewSchema $schema): array
    {
        if (($config['version'] ?? null) !== 'saved-view/1' || array_diff(array_keys($config), ['version', 'filters', 'sorts', 'columns', 'groupBy', 'presentation']) !== []) {
            throw new InvalidArgumentException('Unsupported saved-view configuration.');
        }

        $fields = [];

        foreach ($schema->fields as $field) {
            $fields[$field->key] = $field;
        }

        $filters = $config['filters'] ?? ['op' => 'and', 'args' => []];

        if (! is_array($filters)) {
            throw new InvalidArgumentException('Invalid view filters.');
        }

        $nodes = 0;
        $this->filter(node: $filters, fields: $fields, depth: 1, nodes: $nodes);

        $columns = $config['columns'] ?? [];

        if (! is_array($columns) || count($columns) > 50 || ! array_is_list($columns)) {
            throw new InvalidArgumentException('Invalid view projection.');
        }

        $validatedColumns = [];

        foreach ($columns as $key) {
            $field = is_string($key) ? ($fields[$key] ?? null) : null;

            if (! is_string($key) || $field === null || ! $field->visible || ! $field->projectable) {
                throw new InvalidArgumentException('View projection is not permitted.');
            }

            $validatedColumns[] = $key;
        }

        $sorts = $config['sorts'] ?? [];

        if (! is_array($sorts) || count($sorts) > 5 || ! array_is_list($sorts)) {
            throw new InvalidArgumentException('Invalid view sort.');
        }

        $validatedSorts = [];

        foreach ($sorts as $sort) {
            $this->validateSort(sort: $sort, fields: $fields);
            $validatedSorts[] = $sort;
        }

        $groupBy = $config['groupBy'] ?? null;

        if ($groupBy !== null && (! is_string($groupBy) || ! isset($fields[$groupBy]) || ! $fields[$groupBy]->projectable)) {
            throw new InvalidArgumentException('View group is not permitted.');
        }

        return ['filters' => $filters, 'sorts' => $validatedSorts, 'columns' => $validatedColumns, 'groupBy' => $groupBy];
    }

    /**
     * @param  array<string, ViewField>  $fields
     *
     * @phpstan-assert array{field: string, direction: 'asc'|'desc'} $sort
     */
    private function validateSort(mixed $sort, array $fields): void
    {
        $fieldKey = is_array($sort) ? ($sort['field'] ?? null) : null;
        $direction = is_array($sort) ? ($sort['direction'] ?? null) : null;
        $field = is_string($fieldKey) ? ($fields[$fieldKey] ?? null) : null;

        if (! is_array($sort) || ! is_string($fieldKey) || $field === null || ! $field->sortable || ! in_array($direction, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException('View sort is not permitted.');
        }
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @param  array<string, ViewField>  $fields
     *
     * @phpstan-assert array<string, mixed> $node
     */
    private function filter(array $node, array $fields, int $depth, int &$nodes): void
    {
        if ($depth > 4 || ++$nodes > 50) {
            throw new InvalidArgumentException('View filter is too complex.');
        }

        if (isset($node['args'])) {
            if (array_diff(array_keys($node), ['op', 'args']) !== [] || ! in_array($node['op'] ?? null, ['and', 'or'], true) || ! is_array($node['args'])) {
                throw new InvalidArgumentException('Invalid view filter group.');
            }

            foreach ($node['args'] as $child) {
                if (! is_array($child)) {
                    throw new InvalidArgumentException('Invalid view filter node.');
                }

                $this->filter(node: $child, fields: $fields, depth: $depth + 1, nodes: $nodes);
            }

            return;
        }

        $fieldKey = $node['field'] ?? null;
        $field = is_string($fieldKey) ? ($fields[$fieldKey] ?? null) : null;

        if ($field === null || ! $field->visible || ! $field->filterable || array_diff(array_keys($node), ['field', 'op', 'value']) !== [] || ! in_array($node['op'] ?? null, $field->operators, true)) {
            throw new InvalidArgumentException('View filter is not permitted.');
        }

        if ($node['op'] === 'in' && (! is_array($node['value'] ?? null) || count($node['value']) > 100)) {
            throw new InvalidArgumentException('View filter list is invalid.');
        }
    }
}
