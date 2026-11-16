<?php

declare(strict_types=1);

namespace Packman\Panels\Data\Subjects;

use InvalidArgumentException;
use Packman\Attributes\Api;

/**
 * Validated root-to-leaf subject hierarchy bounded to 16 nodes within a single tenant.
 */
#[Api(since: '1.0')]
final readonly class SubjectChain
{
    public const int MAX_DEPTH = 16;

    public string $tenantId;

    public SubjectNode $leaf;

    /** @var non-empty-list<SubjectNode> */
    public array $ordered;

    /**
     * @param  list<SubjectNode>  $ancestors
     */
    public function __construct(SubjectNode $leaf, array $ancestors = [])
    {
        $rawAncestors = $ancestors !== [] ? $ancestors : $leaf->parents;

        if ($ancestors !== [] && $leaf->parents !== []) {
            throw new InvalidArgumentException('Pass ancestors either on SubjectNode or to SubjectChain, not both.');
        }

        $normalizedLeaf = $leaf->withoutParents();
        $totalCount = 1 + count($rawAncestors);

        if ($totalCount > self::MAX_DEPTH) {
            throw new InvalidArgumentException('Subject chain exceeds maximum depth of 16.');
        }

        $seen = [$normalizedLeaf->key() => true];
        $chain = [$normalizedLeaf];

        foreach ($rawAncestors as $ancestor) {
            if ($ancestor->tenantId !== $normalizedLeaf->tenantId) {
                throw new InvalidArgumentException('SubjectChain cannot cross tenant boundaries.');
            }

            if ($ancestor->parents !== []) {
                throw new InvalidArgumentException('Ancestors in SubjectChain must not contain nested parent chains.');
            }

            if (isset($seen[$ancestor->key()])) {
                throw new InvalidArgumentException('Cycle detected in SubjectChain.');
            }

            $seen[$ancestor->key()] = true;
            $chain[] = $ancestor;
        }

        $this->tenantId = $normalizedLeaf->tenantId;
        $this->leaf = $normalizedLeaf;
        $this->ordered = $chain;
    }

    public static function fromSubject(SubjectNode $subject): self
    {
        return new self($subject);
    }

    /**
     * @return list<SubjectNode>
     */
    public function ancestors(): array
    {
        return array_slice($this->ordered, 1);
    }
}
