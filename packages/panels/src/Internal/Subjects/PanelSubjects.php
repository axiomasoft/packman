<?php

declare(strict_types=1);

namespace Packman\Panels\Internal\Subjects;

use Override;
use Packman\Attributes\Internal;
use Packman\Panels\Contracts\Subjects\PanelSubjectResolver;
use Packman\Panels\Contracts\Subjects\SubjectChainResolver;
use Packman\Panels\Data\Subjects\SubjectChain;
use Packman\Panels\Data\Subjects\SubjectNode;
use Packman\Panels\Exceptions\Subjects\SubjectResolutionFailed;

/**
 * Hierarchical subject resolution with anti-spoofing ancestor re-resolution.
 */
#[Internal]
final readonly class PanelSubjects implements SubjectChainResolver
{
    /**
     * @param  list<string>  $allowedTypes
     */
    public function __construct(
        private PanelSubjectResolver $resolver,
        private array $allowedTypes = [],
    ) {}

    /**
     * @throws SubjectResolutionFailed
     */
    #[Override]
    public function resolve(SubjectNode $leaf): SubjectChain
    {
        if ($this->allowedTypes !== [] && ! in_array($leaf->type, $this->allowedTypes, true)) {
            throw new SubjectResolutionFailed('Unregistered subject type.');
        }

        $chain = $this->resolver->resolve($leaf->withoutParents());

        if ($chain === null || ! $chain->leaf->equals($leaf->withoutParents())) {
            throw new SubjectResolutionFailed('Subject is missing or belongs to another scope.');
        }

        $ancestors = $chain->ancestors();

        foreach ($ancestors as $index => $ancestor) {
            if ($this->allowedTypes !== [] && ! in_array($ancestor->type, $this->allowedTypes, true)) {
                throw new SubjectResolutionFailed('Unregistered ancestor type.');
            }

            $resolved = $this->resolver->resolve($ancestor);
            $expected = new SubjectChain($ancestor, array_slice($ancestors, 0, $index));

            if (
                $resolved === null
                || array_map(static fn (SubjectNode $s): string => $s->key(), $resolved->ordered)
                !== array_map(static fn (SubjectNode $s): string => $s->key(), $expected->ordered)
            ) {
                throw new SubjectResolutionFailed('Orphan subject or conflicting parent.');
            }
        }

        if ($leaf->parents !== [] && ! $leaf->equals(new SubjectNode($leaf->tenantId, $leaf->type, $leaf->id, $ancestors))) {
            throw new SubjectResolutionFailed('Caller ancestry conflicts with resolver.');
        }

        return $chain;
    }

    /**
     * @throws SubjectResolutionFailed
     */
    #[Override]
    public function verify(SubjectChain $supplied): void
    {
        $actual = $this->resolve($supplied->leaf);

        if (
            array_map(static fn (SubjectNode $s): string => $s->key(), $actual->ordered)
            !== array_map(static fn (SubjectNode $s): string => $s->key(), $supplied->ordered)
        ) {
            throw new SubjectResolutionFailed('Caller chain differs from registered ancestry.');
        }
    }
}
