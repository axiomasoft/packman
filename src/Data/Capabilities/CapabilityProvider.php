<?php

declare(strict_types=1);

namespace Packman\Plugins\Data\Capabilities;

use Packman\Attributes\Api;

/**
 * One provider of a capability: an implementation class, or a host config key naming the class with optional default.
 */
#[Api(since: '1.0')]
final readonly class CapabilityProvider
{
    /**
     * @param  class-string  $contract
     * @param  class-string|null  $implementation
     * @param  class-string|null  $default
     */
    public function __construct(
        public string $contract,
        public string $pluginId,
        public ?string $implementation = null,
        public ?string $configKey = null,
        public ?string $default = null,
    ) {}

    /**
     * @param  class-string  $contract
     * @param  class-string  $implementation
     */
    public static function fromClass(string $contract, string $pluginId, string $implementation): self
    {
        return new self(contract: $contract, pluginId: $pluginId, implementation: $implementation);
    }

    /**
     * @param  class-string  $contract
     * @param  class-string|null  $default
     */
    public static function providesFromConfig(string $contract, string $pluginId, string $configKey, ?string $default = null): self
    {
        return new self(contract: $contract, pluginId: $pluginId, configKey: $configKey, default: $default);
    }

    public function fromConfig(): bool
    {
        return $this->configKey !== null;
    }
}
