<?php

declare(strict_types=1);

namespace Packman\Foundation;

use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use LogicException;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Late access to the running application for a long-lived service that must not hold it:
 * under a resident worker (Octane) the active container is replaced per request, so callers
 * resolve the current instance at call time.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class ApplicationLocator
{
    public static function current(): Application
    {
        $application = Container::getInstance();

        if (! $application instanceof Application) {
            throw new LogicException('The active container is not a Laravel application.');
        }

        return $application;
    }
}
