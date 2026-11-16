<?php

declare(strict_types=1);

namespace Packman\Config\Internal;

use BackedEnum;
use Closure;
use LogicException;
use Packman\Attributes\Internal;
use Packman\Config\Data\SecretRef;
use ReflectionClass;
use ReflectionProperty;

/**
 * Lossless object graph <-> array-of-scalars codec for definitions caching.
 * Encodable objects store all instance properties via promoted constructor parameters.
 */
#[Internal]
final class DefinitionsCodec
{
    private const string OBJECT = '@c';

    private const string ENUM = '@e';

    private const string ARRAY = '@a';

    public function encode(mixed $value, string $path = 'definitions'): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if ($value instanceof BackedEnum) {
            return [self::ENUM => $value::class, 'v' => $value->value];
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = $this->encode($item, "{$path}.{$key}");
            }

            return [self::ARRAY => $out];
        }

        if ($value instanceof Closure) {
            throw new LogicException("{$path}: cannot cache a value of type Closure.");
        }

        if ($value instanceof SecretRef) {
            throw new LogicException("{$path}: SecretRef must not be cached.");
        }

        if (is_object($value)) {
            $args = [];
            foreach ($this->promoted($value::class, $path) as $name => $property) {
                $args[$name] = $this->encode($property->getValue($value), "{$path}.{$name}");
            }

            return [self::OBJECT => $value::class, 'a' => $args];
        }

        throw new LogicException("{$path}: cannot cache a value of type ".get_debug_type($value).'.');
    }

    public function decode(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_key_exists(self::ENUM, $value)) {
            $enum = $value[self::ENUM];
            $backing = $value['v'] ?? null;

            if (! is_string($enum) || ! is_a($enum, BackedEnum::class, true) || (! is_string($backing) && ! is_int($backing))) {
                throw new LogicException('Invalid cached enum envelope.');
            }

            return $enum::from($backing);
        }

        if (array_key_exists(self::ARRAY, $value)) {
            if (! is_array($value[self::ARRAY])) {
                throw new LogicException('Invalid cached array envelope.');
            }

            return array_map($this->decode(...), $value[self::ARRAY]);
        }

        $class = $value[self::OBJECT] ?? null;
        $encodedArgs = $value['a'] ?? null;

        if (! is_string($class) || ! class_exists($class) || ! is_array($encodedArgs)) {
            throw new LogicException('Invalid cached object envelope.');
        }

        /** @var array<string, mixed> $decodedArgs */
        $decodedArgs = array_map($this->decode(...), $encodedArgs);

        return new $class(...$decodedArgs);
    }

    /**
     * @param  class-string  $class
     * @return array<string, ReflectionProperty>
     */
    private function promoted(string $class, string $path): array
    {
        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();
        $promoted = [];
        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            if (! $parameter->isPromoted()) {
                throw new LogicException("{$path}: {$class}::__construct() parameter \${$parameter->getName()} is not promoted; the object cannot be cached.");
            }
            $promoted[$parameter->getName()] = $reflection->getProperty($parameter->getName());
        }
        foreach ($reflection->getProperties() as $property) {
            if (! $property->isStatic() && ! array_key_exists($property->getName(), $promoted)) {
                throw new LogicException("{$path}: {$class}::\${$property->getName()} is not a constructor parameter; the object cannot be cached.");
            }
        }

        return $promoted;
    }
}
