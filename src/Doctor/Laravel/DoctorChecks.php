<?php

declare(strict_types=1);

namespace Packman\Doctor\Laravel;

use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Doctor\Contracts\HealthCheck;

/**
 * Supported SPI entry point for tagging {@see DoctorCheck} and {@see HealthCheck} implementations.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class DoctorChecks
{
    public const string TAG = 'packman.doctor_checks';

    public const string HEALTH_CHECK_TAG = 'packman.health_checks';

    /** @param class-string<DoctorCheck> ...$checks */
    public static function register(Application $app, string ...$checks): void
    {
        foreach ($checks as $check) {
            if (! is_subclass_of($check, DoctorCheck::class)) {
                throw new InvalidArgumentException("{$check} must implement ".DoctorCheck::class.'.');
            }

            $app->singletonIf($check);
            $app->tag([$check], self::TAG);
        }
    }

    /** @param class-string<HealthCheck> ...$checks */
    public static function registerHealthChecks(Application $app, string ...$checks): void
    {
        foreach ($checks as $check) {
            if (! is_subclass_of($check, HealthCheck::class)) {
                throw new InvalidArgumentException("{$check} must implement ".HealthCheck::class.'.');
            }

            $app->singletonIf($check);
            $app->tag([$check], self::HEALTH_CHECK_TAG);
        }
    }
}
