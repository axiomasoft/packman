<?php

declare(strict_types=1);

namespace Packman\Doctor\Contracts;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
interface DoctorRegistryContract
{
    /** @return list<DoctorCheck> */
    public function all(): array;

    /**
     * @param  list<string>  $prefixes  empty selects every check
     * @return list<DoctorCheck>
     */
    public function matching(array $prefixes): array;

    /**
     * @param  list<string>  $prefixes
     * @return list<string> prefixes that match no registered check
     */
    public function unmatched(array $prefixes): array;

    /** @return list<HealthCheck> */
    public function healthChecks(): array;
}
