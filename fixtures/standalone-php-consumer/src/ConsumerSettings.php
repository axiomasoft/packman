<?php

declare(strict_types=1);

namespace Fixture\StandalonePhp;

final readonly class ConsumerSettings
{
    public function __construct(
        public string $endpoint,
        public int $retries,
    ) {}
}
