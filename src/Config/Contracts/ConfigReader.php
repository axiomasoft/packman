<?php

declare(strict_types=1);

namespace Packman\Config\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Config\Data\CompileContext;
use Packman\Config\Exceptions\InvalidConfiguration;

/**
 * Typed, validating view of one config subtree. Every accessor returns a valid value: when the raw value
 * is wrong it records a problem under the full key path and returns the default, so a section reports all
 * of its problems in one pass. A missing key or an explicit null yields the default.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
interface ConfigReader
{
    /** Full dotted path of this subtree, e.g. 'packman.messages'. */
    public function path(): string;

    public function context(): CompileContext;

    public function has(string $key): bool;

    /** @param non-empty-string|null $pattern PCRE the value must match */
    public function string(string $key, string $default, bool $allowEmpty = false, ?string $pattern = null, string $doc = ''): string;

    public function nullableString(string $key, ?string $default = null, string $doc = ''): ?string;

    public function int(string $key, int $default, int $min, int $max, string $doc = ''): int;

    public function nullableInt(string $key, ?int $default, int $min, int $max, string $doc = ''): ?int;

    public function float(string $key, float $default, float $min, float $max, string $doc = ''): float;

    public function bool(string $key, bool $default, string $doc = ''): bool;

    public function unsafeBool(string $key, string $doc = ''): bool;

    /** @param non-empty-list<string> $allowed */
    public function oneOf(string $key, string $default, array $allowed, string $doc = ''): string;

    /**
     * @param  list<string>  $default
     * @param  list<string>|null  $allowed
     * @return list<string>
     */
    public function stringList(string $key, array $default, ?array $allowed = null, string $doc = ''): array;

    /**
     * @param  list<int>  $default
     * @return list<int>
     */
    public function intList(string $key, array $default, int $min, int $max, bool $nonEmpty = true, string $doc = ''): array;

    /**
     * @param  array<string, string>  $default
     * @return array<string, string>
     */
    public function stringMap(string $key, array $default, string $doc = ''): array;

    /**
     * @param  array<string, list<string>>  $default
     * @param  list<string>|null  $allowedKeys
     * @param  list<string>|null  $allowedValues
     * @return array<string, list<string>>
     */
    public function stringListMap(string $key, array $default = [], ?array $allowedKeys = null, ?array $allowedValues = null, string $doc = ''): array;

    /**
     * @param  array<string, int>  $default
     * @param  list<string>|null  $allowedKeys
     * @return array<string, int>
     */
    public function intMap(string $key, array $default, int $min, int $max, ?array $allowedKeys = null, string $doc = ''): array;

    /**
     * @template T of object
     * @template D of object
     *
     * @param  class-string<D>  $default
     * @param  class-string<T>  $parent
     * @return class-string<T|D>
     */
    public function className(string $key, string $default, string $parent, string $doc = ''): string;

    /**
     * @template T of object
     * @template D of object
     *
     * @param  class-string<D>|null  $default
     * @param  class-string<T>  $parent
     * @return class-string<T|D>|null
     */
    public function nullableClassName(string $key, ?string $default, string $parent, string $doc = ''): ?string;

    /**
     * @template T of object
     * @template D of object = T
     *
     * @param  list<class-string<D>>  $default
     * @param  class-string<T>  $parent
     * @return list<class-string<T|D>>
     */
    public function classList(string $key, array $default, string $parent, string $doc = ''): array;

    /**
     * Compile a map whose keys are data. An {@see InvalidConfiguration} thrown by the callback becomes a problem at that path.
     *
     * @template T
     *
     * @param  callable(ConfigReader, string): T  $compile
     * @return array<string, T>
     */
    public function each(string $key, callable $compile, string $doc = ''): array;

    /**
     * Read a nested config array as-is when its shape is checked by the section itself.
     *
     * @param  array<array-key, mixed>  $default
     * @return array<array-key, mixed>
     */
    public function subtree(string $key, array $default = [], string $doc = ''): array;

    public function fail(string $key, string $message): void;
}
