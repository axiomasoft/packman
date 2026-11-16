<?php

declare(strict_types=1);

namespace Packman\Testkit\Fakes\Config;

use Packman\Attributes\Api;

/** Compiled settings object returned by ReportTestSection. */
#[Api(since: '1.0')]
final readonly class ReportTestSettings
{
    public function __construct(
        public bool $enabled,
        public string $channel,
    ) {}
}
