<?php

declare(strict_types=1);

namespace Packman\DevTools\Internal\Config;

use Packman\Attributes\Internal;
use Packman\Config\Contracts\ConfigReader;
use Packman\Config\Data\CompileContext;

/**
 * Schema-introspecting ConfigReader capturing declared keys, defaults, constraints, and descriptions.
 */
#[Internal]
final class SchemaRecordingReader implements ConfigReader
{
    /** @var array<string, array<string, mixed>> */
    public array $schema = [];

    public function __construct(
        private readonly string $prefix,
        private readonly CompileContext $context,
    ) {}

    public function path(): string
    {
        return $this->prefix;
    }

    public function context(): CompileContext
    {
        return $this->context;
    }

    public function has(string $key): bool
    {
        return false;
    }

    public function string(string $key, string $default, bool $allowEmpty = false, ?string $pattern = null, string $doc = ''): string
    {
        $this->record($key, array_filter([
            'type' => 'string',
            'default' => $default,
            'minLength' => $allowEmpty ? 0 : 1,
            'pattern' => $pattern,
            'description' => $doc !== '' ? $doc : null,
        ], static fn (mixed $v): bool => $v !== null));

        return $default;
    }

    public function nullableString(string $key, ?string $default = null, string $doc = ''): ?string
    {
        $this->record($key, array_filter([
            'type' => ['string', 'null'],
            'default' => $default,
            'description' => $doc !== '' ? $doc : null,
        ], static fn (mixed $v): bool => $v !== null));

        return $default;
    }

    public function int(string $key, int $default, int $min, int $max, string $doc = ''): int
    {
        $this->record($key, array_filter([
            'type' => 'integer',
            'default' => $default,
            'minimum' => $min,
            'maximum' => $max,
            'description' => $doc !== '' ? $doc : null,
        ], static fn (mixed $v): bool => $v !== null));

        return $default;
    }

    public function nullableInt(string $key, ?int $default, int $min, int $max, string $doc = ''): ?int
    {
        $this->record($key, array_filter([
            'type' => ['integer', 'null'],
            'default' => $default,
            'minimum' => $min,
            'maximum' => $max,
            'description' => $doc !== '' ? $doc : null,
        ], static fn (mixed $v): bool => $v !== null));

        return $default;
    }

    public function float(string $key, float $default, float $min, float $max, string $doc = ''): float
    {
        $this->record($key, array_filter([
            'type' => 'number',
            'default' => $default,
            'minimum' => $min,
            'maximum' => $max,
            'description' => $doc !== '' ? $doc : null,
        ], static fn (mixed $v): bool => $v !== null));

        return $default;
    }

    public function bool(string $key, bool $default, string $doc = ''): bool
    {
        $this->record($key, array_filter([
            'type' => 'boolean',
            'default' => $default,
            'description' => $doc !== '' ? $doc : null,
        ], static fn (mixed $v): bool => $v !== null));

        return $default;
    }

    public function unsafeBool(string $key, string $doc = ''): bool
    {
        $this->record($key, array_filter([
            'type' => 'boolean',
            'default' => false,
            'description' => $doc !== '' ? $doc : null,
        ], static fn (mixed $v): bool => $v !== null));

        return false;
    }

    public function oneOf(string $key, string $default, array $allowed, string $doc = ''): string
    {
        $this->record($key, array_filter([
            'type' => 'string',
            'enum' => $allowed,
            'default' => $default,
            'description' => $doc !== '' ? $doc : null,
        ], static fn (mixed $v): bool => $v !== null));

        return $default;
    }

    public function stringList(string $key, array $default, ?array $allowed = null, string $doc = ''): array
    {
        $items = $allowed !== null ? ['type' => 'string', 'enum' => $allowed] : ['type' => 'string'];
        $this->record($key, array_filter([
            'type' => 'array',
            'items' => $items,
            'default' => $default,
            'description' => $doc !== '' ? $doc : null,
        ], static fn (mixed $v): bool => $v !== null));

        return $default;
    }

    public function intList(string $key, array $default, int $min, int $max, bool $nonEmpty = true, string $doc = ''): array
    {
        $this->record($key, array_filter([
            'type' => 'array',
            'items' => ['type' => 'integer', 'minimum' => $min, 'maximum' => $max],
            'minItems' => $nonEmpty ? 1 : 0,
            'default' => $default,
            'description' => $doc !== '' ? $doc : null,
        ], static fn (mixed $v): bool => $v !== null));

        return $default;
    }

    public function stringMap(string $key, array $default, string $doc = ''): array
    {
        $this->record($key, array_filter([
            'type' => 'object',
            'additionalProperties' => ['type' => 'string'],
            'default' => $default,
            'description' => $doc !== '' ? $doc : null,
        ], static fn (mixed $v): bool => $v !== null));

        return $default;
    }

    public function stringListMap(string $key, array $default = [], ?array $allowedKeys = null, ?array $allowedValues = null, string $doc = ''): array
    {
        $this->record($key, array_filter([
            'type' => 'object',
            'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']],
            'default' => $default,
            'description' => $doc !== '' ? $doc : null,
        ], static fn (mixed $v): bool => $v !== null));

        return $default;
    }

    public function intMap(string $key, array $default, int $min, int $max, ?array $allowedKeys = null, string $doc = ''): array
    {
        $this->record($key, array_filter([
            'type' => 'object',
            'additionalProperties' => ['type' => 'integer', 'minimum' => $min, 'maximum' => $max],
            'default' => $default,
            'description' => $doc !== '' ? $doc : null,
        ], static fn (mixed $v): bool => $v !== null));

        return $default;
    }

    public function className(string $key, string $default, string $parent, string $doc = ''): string
    {
        $this->record($key, array_filter([
            'type' => 'string',
            'default' => $default,
            'description' => $doc !== '' ? $doc : null,
        ], static fn (mixed $v): bool => $v !== null));

        return $default;
    }

    public function nullableClassName(string $key, ?string $default, string $parent, string $doc = ''): ?string
    {
        $this->record($key, array_filter([
            'type' => ['string', 'null'],
            'default' => $default,
            'description' => $doc !== '' ? $doc : null,
        ], static fn (mixed $v): bool => $v !== null));

        return $default;
    }

    public function classList(string $key, array $default, string $parent, string $doc = ''): array
    {
        $this->record($key, array_filter([
            'type' => 'array',
            'items' => ['type' => 'string'],
            'default' => $default,
            'description' => $doc !== '' ? $doc : null,
        ], static fn (mixed $v): bool => $v !== null));

        return $default;
    }

    public function each(string $key, callable $compile, string $doc = ''): array
    {
        $this->record($key, array_filter([
            'type' => 'object',
            'description' => $doc !== '' ? $doc : null,
        ], static fn (mixed $v): bool => $v !== null));

        return [];
    }

    public function subtree(string $key, array $default = [], string $doc = ''): array
    {
        $this->record($key, array_filter([
            'type' => 'object',
            'description' => $doc !== '' ? $doc : null,
        ], static fn (mixed $v): bool => $v !== null));

        return $default;
    }

    public function fail(string $key, string $message): void {}

    /** @param array<string, mixed> $entry */
    private function record(string $key, array $entry): void
    {
        $this->schema[$this->prefix.'.'.$key] = $entry;
    }
}
