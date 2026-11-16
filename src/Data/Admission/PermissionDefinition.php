<?php

declare(strict_types=1);

namespace Packman\Panels\Data\Admission;

use BackedEnum;
use InvalidArgumentException;
use Packman\Attributes\Api;

/**
 * Immutable catalog definition of a panel permission entry.
 */
#[Api(since: '1.0')]
final readonly class PermissionDefinition
{
    public function __construct(
        public string $local,
        public string $authority = 'grants',
        public ?string $label = null,
        public ?string $group = null,
        public ?string $description = null,
        public ?BackedEnum $case = null,
        public ?string $resourceModel = null,
    ) {
        if ($this->local === '') {
            throw new InvalidArgumentException('Permission local name cannot be empty.');
        }

        if (! in_array($this->authority, ['grants', 'policy'], true)) {
            throw new InvalidArgumentException('Permission authority must be "grants" or "policy".');
        }
    }

    public function equals(self $other): bool
    {
        return $this->local === $other->local
            && $this->authority === $other->authority
            && $this->label === $other->label
            && $this->group === $other->group
            && $this->description === $other->description
            && $this->case === $other->case
            && $this->resourceModel === $other->resourceModel;
    }
}
