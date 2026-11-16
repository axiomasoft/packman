<?php

declare(strict_types=1);

namespace Packman\Modules\Data;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * An EntityField extender resolved against the compiled entity set.
 * `sleeping` = declaring module differs from the target entity's owning
 * module: the declaration is collected here regardless, but only takes
 * effect for a tenant where the target module is active — that per-tenant
 * filter is ModuleRegistry's job, not this compiler's.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final readonly class CompiledEntityField
{
    public function __construct(
        public EntityField $extender,
        public string $declaringModule,
        public bool $sleeping,
    ) {}

    /**
     * Plain-array projection for the compiled file. The wrapped
     * {@see EntityField} extender flattens through the shared
     * {@see CompiledRegistry::extenderToArray()} — no `__set_state`.
     *
     * @return array{extender: array<string, mixed>, declaringModule: string, sleeping: bool}
     */
    public function toArray(): array
    {
        return [
            'extender' => CompiledRegistry::extenderToArray($this->extender),
            'declaringModule' => $this->declaringModule,
            'sleeping' => $this->sleeping,
        ];
    }

    /**
     * @param  array{extender: array<string, mixed>, declaringModule: string, sleeping: bool}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            extender: CompiledRegistry::extenderFromArray(class: EntityField::class, data: $data['extender']),
            declaringModule: $data['declaringModule'],
            sleeping: $data['sleeping'],
        );
    }
}
