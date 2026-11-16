<?php

declare(strict_types=1);

namespace Fixture\StandalonePhp;

use Packman\Config\Contracts\ConfigReader;
use Packman\Config\Contracts\ConfigSection;

final class ConsumerConfigSection implements ConfigSection
{
    public function key(): string
    {
        return 'consumer';
    }

    public function settingsClass(): string
    {
        return ConsumerSettings::class;
    }

    public function compile(ConfigReader $config): object
    {
        return new ConsumerSettings(
            endpoint: $config->string('endpoint', 'https://api.example.test'),
            retries: $config->int('retries', 3, 0, 10),
        );
    }
}
