<?php

declare(strict_types=1);

namespace Packman\Panels\Data\Admission;

use Packman\Attributes\Api;
use Packman\Context\Contracts\ContextScopeInterface;

/**
 * Result of panel entry admission, recording the resolved tenant and optional child scope.
 */
#[Api(since: '1.0')]
final readonly class AdmissionResult
{
    public function __construct(
        public ContextScopeInterface $tenant,
        public ?string $scopeType = null,
        public ?string $scopeId = null,
        public bool $scopedWitness = false,
    ) {}

    public function isGlobal(): bool
    {
        return $this->scopeType === null || $this->scopeId === null;
    }
}
