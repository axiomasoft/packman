<?php

declare(strict_types=1);

namespace Packman\Modules\Tests\Stubs;

use Packman\Context\Contracts\ActorResolverInterface;
use Packman\Context\Data\ActorRef;

final class StubActorResolver implements ActorResolverInterface
{
    public function __construct(
        private ActorRef $actor = new ActorRef('system'),
    ) {}

    public function current(): ActorRef
    {
        return $this->actor;
    }

    public function use(ActorRef $actor): void
    {
        $this->actor = $actor;
    }
}
