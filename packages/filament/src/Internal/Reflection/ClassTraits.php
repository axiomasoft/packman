<?php

declare(strict_types=1);

namespace Packman\Filament\Internal\Reflection;

use Packman\Attributes\Internal;
use ReflectionClass;
use ReflectionMethod;

/**
 * Caches recursive trait membership and un-overridden trait method origins per loaded class.
 */
#[Internal]
final class ClassTraits
{
    /** @var array<class-string, array<string, string>> */
    private static array $uses = [];

    /** @var array<class-string, array<class-string, array<string, bool>>> */
    private static array $methods = [];

    /**
     * @param  object|class-string  $class
     */
    public static function has(object|string $class, string $trait): bool
    {
        $name = is_object($class) ? $class::class : $class;

        return in_array($trait, self::$uses[$name] ??= class_uses_recursive($name), true);
    }

    /**
     * @param  object|class-string  $class
     * @param  class-string  $trait
     * @param  list<string>  $methods
     */
    public static function keepsMethods(object|string $class, string $trait, array $methods): bool
    {
        if (! self::has($class, $trait)) {
            return false;
        }

        $name = is_object($class) ? $class::class : $class;

        foreach ($methods as $method) {
            if (! (self::$methods[$name][$trait][$method] ??= new ReflectionMethod($name, $method)->getFileName() === new ReflectionClass($trait)->getFileName())) {
                return false;
            }
        }

        return true;
    }
}
