<?php

declare(strict_types=1);

namespace Packman\Testkit\Concerns\Application;

use Orchestra\Testbench\TestCase;
use Packman\Attributes\Api;

/** Forces a clean Laravel application boot and resets tracked resolved state between assertions. */
#[Api(since: '1.0')]
trait FreshApplication
{
    private int $freshBootCount = 0;

    protected function refreshPackmanApplication(): void
    {
        if ($this instanceof TestCase) {
            $this->refreshApplication();
        }

        $this->freshBootCount++;
    }

    protected function freshBootCount(): int
    {
        return $this->freshBootCount;
    }
}
