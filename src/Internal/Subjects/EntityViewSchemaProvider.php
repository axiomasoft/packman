<?php

declare(strict_types=1);

namespace Packman\Panels\Internal\Subjects;

use LogicException;
use Packman\Attributes\Internal;
use Packman\Modules\Contracts\Entities\EntityRegistry;
use Packman\Modules\Data\Entities\FieldDefinition;
use Packman\Panels\SavedViews\Contracts\ViewSchemaProvider;
use Packman\Panels\SavedViews\Data\ViewContext;
use Packman\Panels\SavedViews\Data\ViewField;
use Packman\Panels\SavedViews\Data\ViewSchema;

/**
 * Saved-view schema provider derived from `Packman\Modules\Contracts\Entities\EntityRegistry` when bound.
 */
#[Internal]
final readonly class EntityViewSchemaProvider implements ViewSchemaProvider
{
    public function __construct(private EntityRegistry $entities) {}

    public function schema(ViewContext $context, string $entityHandle): ViewSchema
    {
        if (! $this->entities->has($entityHandle) || ! in_array($this->entities->get($entityHandle), $this->entities->all($context->tenant), true)) {
            throw new LogicException('Saved-view entity schema is unavailable.');
        }

        $entity = $this->entities->get($entityHandle);

        return new ViewSchema(
            version: 'modules/1',
            entityHandle: $entityHandle,
            fields: array_map(
                static fn (FieldDefinition $field): ViewField => new ViewField(
                    key: $field->handle,
                    type: $field->type,
                    operators: self::operators($field->type),
                    visible: true,
                    filterable: true,
                    sortable: true,
                    projectable: true,
                    mappingKey: $field->handle,
                ),
                $entity->fields,
            ),
        );
    }

    /** @return list<string> */
    private static function operators(string $type): array
    {
        return match ($type) {
            'bool' => ['eq', 'ne', 'is_null'],
            'int', 'decimal', 'money', 'date', 'datetime' => ['eq', 'ne', 'lt', 'lte', 'gt', 'gte', 'in', 'is_null'],
            default => ['eq', 'ne', 'in', 'is_null'],
        };
    }
}
