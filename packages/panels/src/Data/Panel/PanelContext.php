<?php

declare(strict_types=1);

namespace Packman\Panels\Data\Panel;

use InvalidArgumentException;
use Packman\Attributes\Api;
use Packman\Context\Contracts\ContextScopeInterface;

#[Api(since: '1.0')]
final readonly class PanelContext
{
    public function __construct(public ContextScopeInterface $tenant, public string $actorKind, public ?string $actorId)
    {
        if (! in_array($actorKind, ['guest', 'system', 'user'], true)
            || ($actorKind === 'guest' && $actorId !== null)
            || ($actorKind === 'user' && ($actorId === null || $actorId === ''))
            || ($actorKind === 'system' && $actorId !== null && $actorId === '')) {
            throw new InvalidArgumentException('Invalid trusted panel actor.');
        }
    }
}
