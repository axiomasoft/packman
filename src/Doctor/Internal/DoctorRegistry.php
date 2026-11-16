<?php

declare(strict_types=1);

namespace Packman\Doctor\Internal;

use Illuminate\Contracts\Container\Container;
use LogicException;
use Packman\Attributes\Internal;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Doctor\Contracts\DoctorRegistryContract;
use Packman\Doctor\Contracts\HealthCheck;

#[Internal]
final readonly class DoctorRegistry implements DoctorRegistryContract
{
    public const string TAG = 'packman.doctor_checks';

    public const string HEALTH_CHECK_TAG = 'packman.health_checks';

    public function __construct(private Container $container) {}

    /** @return list<DoctorCheck> */
    public function all(): array
    {
        $checks = [];

        foreach ($this->container->tagged(self::TAG) as $check) {
            if (! $check instanceof DoctorCheck) {
                throw new LogicException(sprintf('Service tagged "%s" must implement %s; got %s.', self::TAG, DoctorCheck::class, get_debug_type($check)));
            }

            if (isset($checks[$check->key()])) {
                throw new LogicException(sprintf('Doctor check key "%s" is registered twice.', $check->key()));
            }

            $checks[$check->key()] = $check;
        }

        ksort($checks, SORT_STRING);

        return array_values($checks);
    }

    /**
     * @param  list<string>  $prefixes  empty selects every check
     * @return list<DoctorCheck>
     */
    public function matching(array $prefixes): array
    {
        $checks = $this->all();

        if ($prefixes === []) {
            return $checks;
        }

        return array_values(array_filter($checks, static fn (DoctorCheck $check): bool => array_any($prefixes, fn ($prefix): bool => self::matches($check->key(), $prefix))));
    }

    /**
     * @param  list<string>  $prefixes
     * @return list<string>
     */
    public function unmatched(array $prefixes): array
    {
        $keys = array_map(static fn (DoctorCheck $check): string => $check->key(), $this->all());

        return array_values(array_filter($prefixes, static fn (string $prefix): bool => array_all($keys, fn (string $key): bool => ! self::matches($key, $prefix))));
    }

    /** @return list<HealthCheck> */
    public function healthChecks(): array
    {
        $checks = [];

        foreach ($this->container->tagged(self::HEALTH_CHECK_TAG) as $check) {
            if (! $check instanceof HealthCheck) {
                throw new LogicException(sprintf('Service tagged "%s" must implement %s; got %s.', self::HEALTH_CHECK_TAG, HealthCheck::class, get_debug_type($check)));
            }

            $code = $check->code();

            if (isset($checks[$code])) {
                throw new LogicException('HEALTH_CHECK_KEY_DUPLICATE: '.$code);
            }

            $checks[$code] = $check;
        }

        ksort($checks, SORT_STRING);

        return array_values($checks);
    }

    /** A prefix selects whole key segments: `core.schema` selects `core.schema.tables`, not `core.schemaX`. */
    private static function matches(string $key, string $prefix): bool
    {
        return $key === $prefix || str_starts_with($key, $prefix.'.');
    }
}
