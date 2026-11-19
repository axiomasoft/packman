<?php

declare(strict_types=1);

namespace Packman\Modules\Data\Entities;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Modules\Contracts\Compiler\Extender;
use Packman\Modules\Enums\Entities\Capability;

/**
 * 1. Declares an owned entity into EntityRegistry. `handle` defaults to
 * `{tablePrefix}.{snake(model)}` and `fields` to model `$casts`
 * introspection when left unset — both resolved by RegistryCompiler, which
 * knows the owning manifest this extender belongs to.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class Entity implements Extender
{
    public ?string $handle = null;

    public ?string $table = null;

    public ?string $labelSingular = null;

    public ?string $labelPlural = null;

    public ?string $titleAttribute = null;

    public bool $isSearchable = false;

    public bool $isAuditable = false;

    public bool $hasCustomFields = false;

    public bool $isWorkflowable = false;

    public bool $isCommentable = false;

    public bool $isDocumentable = false;

    /** @var list<Capability> */
    public private(set) array $declaredCapabilities = [];

    /** @var list<FieldDefinition>|null null = introspect model */
    public ?array $fieldDefinitions = null;

    /** @param class-string $model */
    private function __construct(public string $model) {}

    /** @param  class-string  $model */
    public static function make(string $model): self
    {
        return new self($model);
    }

    public function handle(string $handle): self
    {
        $this->handle = $handle;

        return $this;
    }

    public function table(string $table): self
    {
        $this->table = $table;

        return $this;
    }

    public function label(string $singular, string $plural): self
    {
        $this->labelSingular = $singular;
        $this->labelPlural = $plural;

        return $this;
    }

    public function titleAttribute(string $attr): self
    {
        $this->titleAttribute = $attr;

        return $this;
    }

    public function capabilities(Capability ...$capabilities): self
    {
        foreach ($capabilities as $capability) {
            $this->declaredCapabilities[] = $capability;
        }

        return $this;
    }

    public function searchable(): self
    {
        $this->isSearchable = true;

        return $this;
    }

    public function auditable(): self
    {
        $this->isAuditable = true;

        return $this;
    }

    public function customFields(): self
    {
        $this->hasCustomFields = true;

        return $this;
    }

    public function workflowable(): self
    {
        $this->isWorkflowable = true;

        return $this;
    }

    public function commentable(): self
    {
        $this->isCommentable = true;

        return $this;
    }

    public function documentable(): self
    {
        $this->isDocumentable = true;

        return $this;
    }

    /** @param  list<FieldDefinition>  $fields */
    public function fields(array $fields): self
    {
        $this->fieldDefinitions = $fields;

        return $this;
    }
}
