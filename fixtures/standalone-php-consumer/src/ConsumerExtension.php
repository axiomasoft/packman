<?php

declare(strict_types=1);

namespace Fixture\StandalonePhp;

use Packman\Extensions\Concerns\Configuration\Configurable;
use Packman\Extensions\Contracts\Configuration\WithConfiguration;

final class ConsumerExtension implements WithConfiguration
{
    use Configurable;

    public function configRoot(): string
    {
        return 'consumer';
    }

    public function optionsClass(): string
    {
        return ConsumerOptions::class;
    }

    public function configSectionClass(): string
    {
        return ConsumerConfigSection::class;
    }
}
