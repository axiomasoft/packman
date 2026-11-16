<?php

declare(strict_types=1);

namespace Packman\Config\Internal;

use Packman\Attributes\Internal;
use Packman\Config\Contracts\ConfigReader;
use Packman\Config\Data\CompileContext;
use Packman\Config\Data\SecretRef;
use Packman\Config\Exceptions\InvalidConfiguration;

#[Internal]
final readonly class JournalingConfigReader implements ConfigReader
{
    private const string SECRET = '/(?:secrets?|passwords?|tokens?|keys?)$/i';

    /**
     * @param  array<array-key, mixed>  $values  the subtree at $path
     * @param  string|null  $schemaPath  $path with map keys replaced by '*'
     */
    public function __construct(
        private string $path,
        private array $values,
        private CompileJournal $journal,
        private CompileContext $context,
        private ?string $schemaPath = null,
    ) {}

    public function path(): string
    {
        return $this->path;
    }

    public function context(): CompileContext
    {
        return $this->context;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values) && $this->values[$key] !== null;
    }

    public function string(string $key, string $default, bool $allowEmpty = false, ?string $pattern = null, string $doc = ''): string
    {
        $value = $this->take($key, 'string', $default, $doc, ['allow_empty' => $allowEmpty, 'pattern' => $pattern]);

        if ($value === null) {
            return $default;
        }

        if (! is_string($value)) {
            return $this->wrong($key, 'must be a string', $value, $default);
        }

        if (! $allowEmpty && $value === '') {
            return $this->wrong($key, 'must not be empty', $value, $default);
        }

        if ($pattern !== null && preg_match($pattern, $value) !== 1) {
            return $this->wrong($key, "must match {$pattern}", $value, $default);
        }

        return $value;
    }

    public function nullableString(string $key, ?string $default = null, string $doc = ''): ?string
    {
        $value = $this->take($key, '?string', $default, $doc);

        if ($value === null) {
            return $default;
        }

        if (! is_string($value) || $value === '') {
            return $this->wrong($key, 'must be a non-empty string or null', $value, $default);
        }

        return $value;
    }

    public function int(string $key, int $default, int $min, int $max, string $doc = ''): int
    {
        $value = $this->take($key, 'int', $default, $doc, ['min' => $min, 'max' => $max]);

        return $value === null ? $default : $this->checkInt($key, $value, $min, $max, $default);
    }

    public function nullableInt(string $key, ?int $default, int $min, int $max, string $doc = ''): ?int
    {
        $value = $this->take($key, '?int', $default, $doc, ['min' => $min, 'max' => $max]);

        return $value === null ? $default : $this->checkInt($key, $value, $min, $max, $default);
    }

    public function float(string $key, float $default, float $min, float $max, string $doc = ''): float
    {
        $value = $this->take($key, 'float', $default, $doc, ['min' => $min, 'max' => $max]);

        if ($value === null) {
            return $default;
        }

        if (! is_int($value) && ! is_float($value) && (! is_string($value) || ! is_numeric($value))) {
            return $this->wrong($key, 'must be a number', $value, $default);
        }

        $number = (float) $value;

        if (! is_finite($number) || $number < $min || $number > $max) {
            return $this->wrong($key, "must be a finite number between {$min} and {$max}", $value, $default);
        }

        return $number;
    }

    public function bool(string $key, bool $default, string $doc = ''): bool
    {
        $value = $this->take($key, 'bool', $default, $doc);

        return $value === null ? $default : $this->checkBool($key, $value, $default);
    }

    public function unsafeBool(string $key, string $doc = ''): bool
    {
        $value = $this->take($key, 'bool', false, $doc, ['unsafe' => true]);
        $on = $value !== null && $this->checkBool($key, $value, false);

        if ($on) {
            $this->journal->unsafe($this->full($key));
        }

        return $on;
    }

    public function oneOf(string $key, string $default, array $allowed, string $doc = ''): string
    {
        $value = $this->take($key, 'enum', $default, $doc, ['allowed' => $allowed]);

        if ($value === null) {
            return $default;
        }

        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            return $this->wrong($key, 'must be one of ['.implode(', ', $allowed).']', $value, $default);
        }

        return $value;
    }

    public function stringList(string $key, array $default, ?array $allowed = null, string $doc = ''): array
    {
        $value = $this->take($key, 'list<string>', $default, $doc, ['allowed' => $allowed]);

        if ($value === null) {
            return $default;
        }

        if (! is_array($value) || ! array_is_list($value)) {
            return $this->wrong($key, 'must be a list of strings', $value, $default);
        }

        $out = [];
        foreach ($value as $i => $item) {
            if (! is_string($item) || $item === '') {
                return $this->wrong($key, "item {$i} must be a non-empty string", $item, $default, $key.'.'.$i);
            }

            if ($allowed !== null && ! in_array($item, $allowed, true)) {
                return $this->wrong($key, "item {$i} must be one of [".implode(', ', $allowed).']', $item, $default, $key.'.'.$i);
            }
            $out[] = $item;
        }

        return $out;
    }

    public function stringListMap(string $key, array $default = [], ?array $allowedKeys = null, ?array $allowedValues = null, string $doc = ''): array
    {
        $value = $this->take($key, 'map<list<string>>', $default, $doc, ['allowed_keys' => $allowedKeys, 'allowed' => $allowedValues]);

        if ($value === null) {
            return $default;
        }

        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            return $this->wrong($key, 'must be a map of string lists', $value, $default);
        }

        $out = [];
        foreach ($value as $name => $items) {
            if (! is_string($name) || $name === '' || ($allowedKeys !== null && ! in_array($name, $allowedKeys, true))) {
                $this->fail($key.'.'.$name, 'unknown map key ['.$name.']');

                continue;
            }

            if (! is_array($items) || ! array_is_list($items)) {
                $this->fail($key.'.'.$name, 'must be a list of strings');

                continue;
            }

            $validItems = [];
            $valid = true;
            foreach ($items as $index => $item) {
                if (! is_string($item) || $item === '' || ($allowedValues !== null && ! in_array($item, $allowedValues, true))) {
                    $this->fail($key.'.'.$name.'.'.$index, 'must be a non-empty allowed string');
                    $valid = false;
                } else {
                    $validItems[] = $item;
                }
            }

            if ($valid) {
                $out[$name] = $validItems;
            }
        }

        return $out;
    }

    public function intList(string $key, array $default, int $min, int $max, bool $nonEmpty = true, string $doc = ''): array
    {
        $value = $this->take($key, 'list<int>', $default, $doc, ['min' => $min, 'max' => $max, 'non_empty' => $nonEmpty]);

        if ($value === null) {
            return $default;
        }

        if (! is_array($value) || ! array_is_list($value) || ($nonEmpty && $value === [])) {
            return $this->wrong($key, $nonEmpty ? 'must be a non-empty list of integers' : 'must be a list of integers', $value, $default);
        }

        $out = [];
        foreach ($value as $i => $item) {
            if (! $this->isIntLike($item) || (int) $item < $min || (int) $item > $max) {
                return $this->wrong($key, "item {$i} must be an integer between {$min} and {$max}", $item, $default, $key.'.'.$i);
            }
            $out[] = (int) $item;
        }

        return $out;
    }

    public function stringMap(string $key, array $default, string $doc = ''): array
    {
        $value = $this->take($key, 'map<string,string>', $default, $doc);

        if ($value === null) {
            return $default;
        }

        if (! is_array($value)) {
            return $this->wrong($key, 'must be a map of non-empty strings', $value, $default);
        }

        $out = [];
        foreach ($value as $mapKey => $item) {
            if (! is_string($mapKey) || $mapKey === '' || ! is_string($item) || $item === '') {
                $redactContext = is_string($mapKey) && $mapKey !== '' ? $key.'.'.$mapKey : $key;

                return $this->wrong($key, 'must be a map of non-empty strings', $item, $default, $redactContext);
            }
            $out[$mapKey] = $item;
        }

        return $out;
    }

    public function intMap(string $key, array $default, int $min, int $max, ?array $allowedKeys = null, string $doc = ''): array
    {
        $value = $this->take($key, 'map<string,int>', $default, $doc, ['min' => $min, 'max' => $max, 'allowed_keys' => $allowedKeys]);

        if ($value === null) {
            return $default;
        }

        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            return $this->wrong($key, 'must be a map of integers', $value, $default);
        }

        $out = [];
        foreach ($value as $mapKey => $item) {
            if ($allowedKeys !== null && ! in_array((string) $mapKey, $allowedKeys, true)) {
                return $this->wrong($key, "key {$mapKey} must be one of [".implode(', ', $allowedKeys).']', $mapKey, $default, $key.'.'.$mapKey);
            }

            if (! $this->isIntLike($item) || (int) $item < $min || (int) $item > $max) {
                return $this->wrong($key, "item {$mapKey} must be an integer between {$min} and {$max}", $item, $default, $key.'.'.$mapKey);
            }
            $out[(string) $mapKey] = (int) $item;
        }

        return $out;
    }

    public function className(string $key, string $default, string $parent, string $doc = ''): string
    {
        $value = $this->take($key, 'class-string', $default, $doc, ['parent' => $parent]);

        return $value === null ? $default : $this->checkClass($key, $value, $parent, $default);
    }

    public function nullableClassName(string $key, ?string $default, string $parent, string $doc = ''): ?string
    {
        $value = $this->take($key, '?class-string', $default, $doc, ['parent' => $parent]);

        return $value === null ? $default : $this->checkClass($key, $value, $parent, $default);
    }

    public function classList(string $key, array $default, string $parent, string $doc = ''): array
    {
        $value = $this->take($key, 'list<class-string>', $default, $doc, ['parent' => $parent]);

        if ($value === null) {
            return $default;
        }

        if (! is_array($value) || ! array_is_list($value)) {
            return $this->wrong($key, 'must be a list of class names', $value, $default);
        }

        $classes = [];
        foreach ($value as $i => $class) {
            $checked = $this->checkClass("{$key}.{$i}", $class, $parent, null, $key);

            if ($checked === null) {
                return $default;
            }
            $classes[] = $checked;
        }

        return $classes;
    }

    public function each(string $key, callable $compile, string $doc = ''): array
    {
        $value = $this->take($key, 'map', [], $doc, consume: false);
        $this->journal->container($this->full($key));

        if ($value === null) {
            return [];
        }

        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            return $this->wrong($key, 'must be a map keyed by name', $value, []);
        }

        $out = [];
        foreach ($value as $name => $item) {
            $path = $this->full($key).'.'.$name;

            if (! is_array($item)) {
                $this->journal->problem($path, 'must be an array');

                continue;
            }
            $this->journal->container($path);
            $child = new self($path, $item, $this->journal, $this->context, $this->schema($key).'.*');

            try {
                $out[(string) $name] = $compile($child, (string) $name);
            } catch (InvalidConfiguration $e) {
                $this->journal->problem($path, $e->getMessage());
            }
        }

        return $out;
    }

    public function subtree(string $key, array $default = [], string $doc = ''): array
    {
        $value = $this->take($key, 'array', $default, $doc);

        if ($value === null) {
            return $default;
        }

        return is_array($value) ? $value : $this->wrong($key, 'must be an array', $value, $default);
    }

    public function fail(string $key, string $message): void
    {
        $this->journal->problem($this->full($key), $message);
    }

    /** @param array<string, mixed> $constraints */
    private function take(string $key, string $type, mixed $default, string $doc, array $constraints = [], bool $consume = true): mixed
    {
        if ($consume) {
            $this->journal->consume($this->full($key));
        }

        $schemaDefault = $this->sanitizeSchemaDefault($key, $default);

        $this->journal->describe($this->schema($key), array_filter(
            ['type' => $type, 'default' => $schemaDefault, 'doc' => $doc] + $constraints,
            static fn (mixed $v, string $k): bool => ($v !== null && $v !== false) || $k === 'default',
            ARRAY_FILTER_USE_BOTH,
        ));

        return $this->values[$key] ?? null;
    }

    private function sanitizeSchemaDefault(string $key, mixed $default): mixed
    {
        if ($default instanceof SecretRef) {
            return '[redacted]';
        }

        if ($this->isSecretKey($key) && $default !== null && $default !== '' && $default !== []) {
            return '[redacted]';
        }

        if (is_array($default)) {
            $sanitized = [];
            foreach ($default as $k => $v) {
                $sanitized[$k] = is_string($k) && $this->isSecretKey($k) && $v !== null && $v !== ''
                    ? '[redacted]'
                    : $v;
            }

            return $sanitized;
        }

        return $default;
    }

    /**
     * @template D of int|null
     *
     * @param  D  $default
     * @return int|D
     */
    private function checkInt(string $key, mixed $value, int $min, int $max, ?int $default): ?int
    {
        if (! $this->isIntLike($value)) {
            return $this->wrong($key, 'must be an integer', $value, $default);
        }

        $int = (int) $value;

        if ($int < $min || $int > $max) {
            return $this->wrong($key, "must be between {$min} and {$max}", $value, $default);
        }

        return $int;
    }

    private function checkBool(string $key, mixed $value, bool $default): bool
    {
        return match (true) {
            is_bool($value) => $value,
            in_array($value, [1, '1', 'true'], true) => true,
            in_array($value, [0, '0', 'false'], true) => false,
            default => $this->wrong($key, 'must be a boolean', $value, $default),
        };
    }

    /**
     * @template T of object
     * @template D of string|null
     *
     * @param  class-string<T>  $parent
     * @param  D  $default
     * @return class-string<T>|D
     */
    private function checkClass(string $key, mixed $value, string $parent, ?string $default, ?string $redactKey = null): ?string
    {
        if (! is_string($value) || $value === '') {
            return $this->wrong($key, 'must be a class name', $value, $default, $redactKey);
        }

        if (! class_exists($value) && ! interface_exists($value)) {
            return $this->wrong($key, 'names an unknown class', $value, $default, $redactKey);
        }

        if (! is_a($value, $parent, true)) {
            return $this->wrong($key, "must implement or extend {$parent}", $value, $default, $redactKey);
        }

        return $value;
    }

    /** @phpstan-assert-if-true int|numeric-string $value */
    private function isIntLike(mixed $value): bool
    {
        return is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1);
    }

    /**
     * @template T
     *
     * @param  T  $fallback
     * @return T
     */
    private function wrong(string $key, string $message, mixed $value, mixed $fallback, ?string $redactKey = null): mixed
    {
        $this->journal->problem($this->full($key), "{$message}, got ".$this->render($redactKey ?? $key, $value));

        return $fallback;
    }

    private function render(string $key, mixed $value): string
    {
        if ($value instanceof SecretRef || $this->isSecretKey($key)) {
            return '<redacted>';
        }

        return match (true) {
            is_string($value) => '"'.(strlen($value) > 60 ? substr($value, 0, 57).'...' : $value).'"',
            is_int($value), is_float($value) => (string) $value,
            is_bool($value) => $value ? 'true' : 'false',
            is_array($value) => 'array('.count($value).')',
            default => get_debug_type($value),
        };
    }

    private function isSecretKey(string $key): bool
    {
        return array_any(explode('.', $this->full($key)), fn ($segment): bool => preg_match(self::SECRET, $segment) === 1);
    }

    private function full(string $key): string
    {
        return $this->path.'.'.$key;
    }

    private function schema(string $key): string
    {
        return ($this->schemaPath ?? $this->path).'.'.$key;
    }
}
