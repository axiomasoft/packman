<?php

declare(strict_types=1);

namespace Packman\Modules\Data\Compiler;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Compiler-private IR. Not a runtime registry and not queryable after compile.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final readonly class NormalizedExtension
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $point,
        public string $id,
        public string $owner,
        public string $ownerVersion,
        public array $payload,
        public ?string $after = null,
    ) {}

    public function key(): string
    {
        return $this->point."\0".$this->id;
    }

    /** @return array{point: string, id: string, owner: string, ownerVersion: string, payload: array<string, mixed>} */
    public function canonical(): array
    {
        return [
            'point' => $this->point,
            'id' => $this->id,
            'owner' => $this->owner,
            'ownerVersion' => $this->ownerVersion,
            'payload' => $this->payload,
        ];
    }
}
