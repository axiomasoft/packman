<?php

declare(strict_types=1);

namespace Packman\Modules\Internal\Gate;

use Packman\Modules\Contracts\ModuleActivationGate;

/**
 * Result of {@see ModuleActivationGate::check()}: allow, or deny with a
 * reason and an optional upgrade hint (e.g. a billing plan slug) for the
 * caller to surface to the user.
 *
 * @internal spec: B-10 §3.3
 */
final readonly class ModuleActivationDecision
{
    private function __construct(
        public bool $allowed,
        public ?string $reason = null,
        public ?string $upgradeHint = null,
    ) {}

    public static function allow(): self
    {
        return new self(allowed: true);
    }

    public static function deny(string $reason, ?string $upgradeHint = null): self
    {
        return new self(allowed: false, reason: $reason, upgradeHint: $upgradeHint);
    }
}
