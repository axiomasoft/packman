<?php

declare(strict_types=1);

namespace Packman\Panels\Data\Subjects;

use InvalidArgumentException;
use Packman\Attributes\Api;

/**
 * Tenant-scoped hierarchical subject identifier used for anti-spoofing chain resolution.
 */
#[Api(since: '1.0')]
final readonly class SubjectNode
{
    public const int MAX_PARENTS = 15;

    /** @var list<self> */
    public array $parents;

    /**
     * @param  list<self>  $parents
     */
    public function __construct(
        public string $tenantId,
        public string $type,
        public string $id,
        array $parents = [],
    ) {
        if ($tenantId === '' || $tenantId === '*' || preg_match('/^[a-z0-9][a-z0-9_.:-]{0,127}$/D', $tenantId) !== 1) {
            throw new InvalidArgumentException('Tenant key must be a non-empty canonical identifier and never a wildcard.');
        }

        if (preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/D', $type) !== 1) {
            throw new InvalidArgumentException('Subject type must be a valid registered identifier.');
        }

        if ($id === '' || strlen($id) > 255 || preg_match('/[\x00-\x20\x7f]/', $id) === 1) {
            throw new InvalidArgumentException('Subject opaque id must be non-empty and free of whitespace or control bytes.');
        }

        if (count($parents) > self::MAX_PARENTS) {
            throw new InvalidArgumentException('Subject parent chain exceeds maximum depth of 16.');
        }

        $seen = [json_encode([$type, $id], JSON_THROW_ON_ERROR) => true];
        $normalizedParents = [];

        foreach ($parents as $parent) {
            if ($parent->tenantId !== $tenantId) {
                throw new InvalidArgumentException('Cross-tenant parent in subject hierarchy is denied.');
            }

            if ($parent->parents !== []) {
                throw new InvalidArgumentException('Ordered parent chain must be flat; nested parent lists are ambiguous.');
            }

            $key = json_encode([$parent->type, $parent->id], JSON_THROW_ON_ERROR);

            if (isset($seen[$key])) {
                throw new InvalidArgumentException('Cycle detected in subject parent chain.');
            }

            $seen[$key] = true;
            $normalizedParents[] = $parent;
        }

        $this->parents = $normalizedParents;
    }

    public function key(): string
    {
        return implode(':', array_map(rawurlencode(...), [$this->tenantId, $this->type, $this->id]));
    }

    public function withoutParents(): self
    {
        return $this->parents === [] ? $this : new self($this->tenantId, $this->type, $this->id);
    }

    public function equals(self $other): bool
    {
        if ($this->tenantId !== $other->tenantId || $this->type !== $other->type || $this->id !== $other->id) {
            return false;
        }

        if (count($this->parents) !== count($other->parents)) {
            return false;
        }

        return array_all($this->parents, fn (SubjectNode $parent, $index): bool => $parent->equals($other->parents[$index]));
    }
}
