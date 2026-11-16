<?php

declare(strict_types=1);

namespace Packman\Modules\Tests\Stubs;

use Packman\Context\Contracts\ContextResolverInterface;
use Packman\Context\Contracts\ContextScopeInterface;
use Packman\Context\Data\ScopeRef;

final class StubContextResolver implements ContextResolverInterface
{
    public function __construct(
        private ?ContextScopeInterface $scope = new ScopeRef('acct-test'),
    ) {}

    public function current(): ?ContextScopeInterface
    {
        return $this->scope;
    }

    public function use(?ContextScopeInterface $scope): void
    {
        $this->scope = $scope;
    }
}
