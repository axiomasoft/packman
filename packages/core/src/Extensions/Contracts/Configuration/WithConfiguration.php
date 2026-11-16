<?php

declare(strict_types=1);

namespace Packman\Extensions\Contracts\Configuration;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Config\Contracts\ConfigSection;
use Packman\Config\Contracts\OptionsFactory;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface WithConfiguration
{
    public function configRoot(): string;

    public function defaultConfigPath(): ?string;

    /** @return class-string<OptionsFactory>|null */
    public function optionsClass(): ?string;

    /** @return class-string<ConfigSection>|null */
    public function configSectionClass(): ?string;
}
