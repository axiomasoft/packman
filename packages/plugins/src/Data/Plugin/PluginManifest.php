<?php

declare(strict_types=1);

namespace Packman\Plugins\Data\Plugin;

use Packman\Attributes\Api;
use Packman\Config\Exceptions\InvalidConfiguration;

/**
 * Identity, version, dependencies, and optional resource/database matrix of an ExtensionPlugin.
 */
#[Api(since: '1.0')]
final readonly class PluginManifest
{
    public const string ID_PATTERN = '/\A[a-z0-9]+(?:-[a-z0-9]+)*(?:\.[a-z0-9]+(?:-[a-z0-9]+)*)+\z/';

    private const string SEMVER_PATTERN = '/\A(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(-((0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*)(\.(0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*))*))?(\+([0-9a-zA-Z-]+(\.[0-9a-zA-Z-]+)*))?\z/';

    /** @var list<string> */
    public const array DATABASES = ['pgsql', 'mysql', 'sqlite'];

    /**
     * @param  string  $id  `vendor.name`, dot-separated lowercase kebab-case segments
     * @param  string  $version  strict SemVer
     * @param  list<string>  $requires  plugin ids that must be registered
     * @param  list<string>  $optional  plugin ids loaded first when present
     * @param  ?string  $resourceRoot  absolute `resources` directory without `..` segments
     * @param  list<string>  $databases  supported DB engines (`pgsql`, `mysql`, `sqlite`); empty = all
     */
    public function __construct(
        public string $id,
        public string $version = '1.0.0',
        public array $requires = [],
        public array $optional = [],
        public ?string $resourceRoot = null,
        public array $databases = [],
    ) {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new InvalidConfiguration(["plugin.manifest.id: '{$id}' is not a plugin id (vendor.name, lowercase letters, digits, dots and dashes)"]);
        }

        if (preg_match(self::SEMVER_PATTERN, $version) !== 1) {
            throw new InvalidConfiguration(["plugin.manifest.version: '{$version}' is not a semver version"]);
        }

        self::assertIds('requires', $id, $requires);
        self::assertIds('optional', $id, $optional);

        if (array_intersect($requires, $optional) !== []) {
            throw new InvalidConfiguration(['plugin.manifest.optional: a plugin cannot be both required and optional']);
        }

        if ($resourceRoot !== null && (preg_match('#^(?:/|[A-Za-z]:[\\\\/])#', $resourceRoot) !== 1 || in_array('..', self::segments($resourceRoot), true))) {
            throw new InvalidConfiguration(["plugin.manifest.resource_root: '{$id}' resourceRoot must be an absolute path without '..' segments"]);
        }

        foreach ($databases as $database) {
            if (! in_array($database, self::DATABASES, true)) {
                throw new InvalidConfiguration(["plugin.manifest.databases: '{$id}' database '{$database}' is not one of ".implode(', ', self::DATABASES)]);
            }
        }
    }

    /** @param list<mixed> $ids */
    private static function assertIds(string $field, string $self, array $ids): void
    {
        $seen = [];

        foreach ($ids as $id) {
            if (! is_string($id) || preg_match(self::ID_PATTERN, $id) !== 1) {
                throw new InvalidConfiguration(["plugin.manifest.{$field}: expected plugin ids (vendor.name)"]);
            }

            if ($id === $self) {
                throw new InvalidConfiguration(["plugin.manifest.{$field}: plugin '{$self}' lists itself"]);
            }

            if (isset($seen[$id])) {
                throw new InvalidConfiguration(["plugin.manifest.{$field}: '{$id}' is listed twice"]);
            }

            $seen[$id] = true;
        }
    }

    /** @return list<string> */
    private static function segments(string $path): array
    {
        $segments = preg_split('#[\\\\/]#', $path);

        return $segments === false ? [$path] : $segments;
    }
}
