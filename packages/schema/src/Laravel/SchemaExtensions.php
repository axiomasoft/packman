<?php

declare(strict_types=1);

namespace Packman\Schema\Laravel;

use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Extensions\ExtensionInspector;
use Packman\Schema\Contracts\Compiler\SchemaOwnership;
use Packman\Schema\Contracts\Migration\SchemaSetProvider;

/**
 * Shared Laravel helper wiring {@see WithSchema} table nouns and {@see SchemaSetProvider} tags.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class SchemaExtensions
{
    /** @param object|class-string $extension */
    public static function register(Application $app, object|string $extension, ?string $ownerId = null): void
    {
        $instance = is_string($extension) ? self::instantiate($extension) : $extension;
        $profile = ExtensionInspector::inspect($instance);

        if (! $profile->hasSchema) {
            return;
        }

        foreach ($profile->schemaSetProviders as $provider) {
            if (! is_subclass_of($provider, SchemaSetProvider::class)) {
                throw new InvalidArgumentException("{$provider} must implement ".SchemaSetProvider::class.'.');
            }

            $app->singletonIf($provider);
            $app->tag([$provider], SchemaSetProvider::class);
        }

        $owner = $ownerId ?? ($profile->configRoot !== null && $profile->configRoot !== '' ? $profile->configRoot : $instance::class);
        $nouns = $profile->tableNouns;

        if ($nouns === []) {
            return;
        }

        $declare = static function (SchemaOwnership $ownership) use ($owner, $nouns): void {
            $ownership->declare($owner, $nouns);
        };

        if ($app->resolved(SchemaOwnership::class)) {
            $declare($app->make(SchemaOwnership::class));

            return;
        }

        $app->afterResolving(SchemaOwnership::class, static function (mixed $ownership) use ($declare): void {
            if ($ownership instanceof SchemaOwnership) {
                $declare($ownership);
            }
        });
    }

    /** @param class-string $class */
    private static function instantiate(string $class): object
    {
        if (! class_exists($class)) {
            throw new InvalidArgumentException("Schema extension class [{$class}] does not exist.");
        }

        return new $class;
    }
}
