<?php

declare(strict_types=1);

namespace Packman\Modules\Data;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Modules\Enums\Capability;

/**
 * Immutable description of a registered entity. Handle is the canonical
 * morph key ({module_short}.{entity}, e.g. 'crm.deal') and drives the morph
 * map, satellite hookup and auto-CRUD/search/admin generation.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final readonly class EntityDefinition
{
    /**
     * @param  class-string  $model
     * @param  list<Capability>  $capabilities
     * @param  list<FieldDefinition>  $fields
     */
    public function __construct(
        public string $handle,
        public string $model,
        public string $table,
        public string $module,
        public string $labelSingular,
        public string $labelPlural,
        public array $capabilities,
        public array $fields,
        public ?string $titleAttribute,
    ) {}

    /**
     * Plain-array projection for the compiled registry file. Enums
     * collapse to their backing `->value`, the class-string stays a string,
     * nested {@see FieldDefinition}s recurse — no object survives into the
     * persisted payload (`__set_state`/serialize are banned for the hot path).
     *
     * @return array{handle: string, model: class-string, table: string, module: string, labelSingular: string, labelPlural: string, capabilities: list<string>, fields: list<array{handle: string, type: 'string'|'int'|'decimal'|'bool'|'date'|'datetime'|'json'|'money'|'relation'|'enum', required: bool, searchable: bool, meta: array<string, mixed>|null}>, titleAttribute: string|null}
     */
    public function toArray(): array
    {
        return [
            'handle' => $this->handle,
            'model' => $this->model,
            'table' => $this->table,
            'module' => $this->module,
            'labelSingular' => $this->labelSingular,
            'labelPlural' => $this->labelPlural,
            'capabilities' => array_map(static fn (Capability $capability): string => $capability->value, $this->capabilities),
            'fields' => array_map(static fn (FieldDefinition $field): array => $field->toArray(), $this->fields),
            'titleAttribute' => $this->titleAttribute,
        ];
    }

    /**
     * @param  array{handle: string, model: class-string, table: string, module: string, labelSingular: string, labelPlural: string, capabilities: list<string>, fields: list<array{handle: string, type: 'string'|'int'|'decimal'|'bool'|'date'|'datetime'|'json'|'money'|'relation'|'enum', required?: bool, searchable?: bool, meta?: array<string, mixed>|null}>, titleAttribute: string|null}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            handle: $data['handle'],
            model: $data['model'],
            table: $data['table'],
            module: $data['module'],
            labelSingular: $data['labelSingular'],
            labelPlural: $data['labelPlural'],
            capabilities: array_map(Capability::from(...), $data['capabilities']),
            fields: array_map(FieldDefinition::fromArray(...), $data['fields']),
            titleAttribute: $data['titleAttribute'],
        );
    }
}
