<?php

declare(strict_types=1);

namespace Packman\Testkit\Fakes\Context;

use Packman\Attributes\Api;
use Packman\Testkit\Contracts\Tenancy\TenantResolverInterface;

/** Mutable in-memory tenant context resolver with scoped actingAsTenant() restoration. */
#[Api(since: '1.0')]
final class MutableTenantResolver implements TenantResolverInterface
{
    public function __construct(private ?string $tenantId = null) {}

    public function currentTenantId(): ?string
    {
        return $this->tenantId;
    }

    public function setTenantId(?string $tenantId): void
    {
        $this->tenantId = $tenantId;
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function actingAsTenant(?string $tenantId, callable $callback): mixed
    {
        $previous = $this->tenantId;
        $this->tenantId = $tenantId;

        try {
            return $callback();
        } finally {
            $this->tenantId = $previous;
        }
    }
}
