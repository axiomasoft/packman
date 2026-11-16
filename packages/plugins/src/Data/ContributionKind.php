<?php

declare(strict_types=1);

namespace Packman\Plugins\Data;

use Packman\Attributes\Api;
use Packman\Config\Exceptions\InvalidConfiguration;

/**
 * A closed slot that plugins contribute to, declared by the package that owns its contract.
 */
#[Api(since: '1.0')]
final readonly class ContributionKind
{
    /**
     * @param  string  $name  snake_case, e.g. `outbox_consumer`
     * @param  class-string  $interface  every contribution must implement it and expose `key(): string`
     */
    public function __construct(
        public string $name,
        public string $interface,
        public string $ownerPluginId,
    ) {
        if (preg_match('/\A[a-z][a-z0-9]*(_[a-z0-9]+)*\z/', $name) !== 1) {
            throw new InvalidConfiguration(["plugin.kind.name: '{$name}' is not a snake_case kind name"]);
        }

        if (! interface_exists($interface) && ! class_exists($interface)) {
            throw new InvalidConfiguration(["plugin.kind.interface: kind '{$name}': '{$interface}' does not exist"]);
        }

        if (preg_match(PluginManifest::ID_PATTERN, $ownerPluginId) !== 1) {
            throw new InvalidConfiguration(["plugin.kind.owner: kind '{$name}': '{$ownerPluginId}' is not a plugin id"]);
        }
    }
}
