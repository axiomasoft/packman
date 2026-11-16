<?php

declare(strict_types=1);

namespace Packman\Testkit\Contracts\Tenancy;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** Minimal tenant context resolver contract for multi-tenant test suites. */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface TenantResolverInterface
{
    public function currentTenantId(): ?string;
}
