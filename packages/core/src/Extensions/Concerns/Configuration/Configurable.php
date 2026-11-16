<?php

declare(strict_types=1);

namespace Packman\Extensions\Concerns\Configuration;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Config\Contracts\ConfigSection;
use Packman\Config\Contracts\OptionsFactory;
use Packman\Extensions\Contracts\Configuration\WithConfiguration;

/**
 * Default implementation for {@see WithConfiguration}; override `configRoot()` and whichever configuration facets your extension supplies.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
trait Configurable
{
    public function defaultConfigPath(): ?string
    {
        return null;
    }

    /** @return class-string<OptionsFactory>|null */
    public function optionsClass(): ?string
    {
        return null;
    }

    /** @return class-string<ConfigSection>|null */
    public function configSectionClass(): ?string
    {
        return null;
    }
}
