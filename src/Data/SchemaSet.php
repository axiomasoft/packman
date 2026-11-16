<?php

declare(strict_types=1);

namespace Packman\Schema\Data;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Schema\Enums\SchemaLifecycle;
use Packman\Schema\Enums\SchemaScope;
use Packman\Schema\Exceptions\SchemaSetException;

/**
 * One package-owned directory of migrations that always runs together on one kind of database.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final readonly class SchemaSet
{
    /**
     * @param  list<string>  $drivers
     * @param  list<string>  $requires
     */
    public function __construct(
        public string $package,
        public string $name,
        public SchemaScope $scope,
        public string $path,
        public int $version,
        public array $drivers = ['pgsql'],
        public SchemaLifecycle $lifecycle = SchemaLifecycle::Platform,
        public array $requires = [],
        public ?string $connection = null,
    ) {
        if (preg_match('/^[a-z0-9_.-]+\/[a-z0-9_.-]+$/', $package) !== 1 || $path === '' || ! self::absolutePath($path) || $connection === ''
            || preg_match('/^[a-z0-9-]+$/', $name) !== 1 || $version < 1 || ! in_array('pgsql', $drivers, true)
            || ($scope === SchemaScope::Dedicated) !== ($connection !== null)) {
            throw SchemaSetException::invalidSet($package.':'.$name);
        }
    }

    public function id(): string
    {
        return $this->package.':'.$this->name;
    }

    private static function absolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || str_starts_with($path, '\\\\')
            || preg_match('~^[a-zA-Z]:[\\\\/]~', $path) === 1;
    }
}
