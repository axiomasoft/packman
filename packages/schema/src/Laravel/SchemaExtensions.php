<?php

declare(strict_types=1);

namespace Packman\Schema\Laravel;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use InvalidArgumentException;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Extensions\ExtensionInspector;
use Packman\Schema\Contracts\Compiler\SchemaOwnership;
use Packman\Schema\Contracts\Compiler\ScopedSchemaOwnership;
use Packman\Schema\Contracts\Migration\ModuleMigrationBoundary;
use Packman\Schema\Contracts\Migration\SchemaSetProvider;
use WeakMap;

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

        self::registerProviders($app, ...$profile->schemaSetProviders);
        self::registerExtensionModuleBoundary($app);

        $owner = $ownerId ?? ($profile->configRoot !== null && $profile->configRoot !== '' ? $profile->configRoot : $instance::class);
        $nouns = $profile->tableNouns;

        if ($nouns === []) {
            return;
        }

        $prefix = $profile->tablePrefix;
        $declare = static function (SchemaOwnership $ownership) use ($owner, $nouns, $prefix): void {
            self::ownershipFor($ownership, $prefix)->declare($owner, $nouns);
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

    public static function ownershipFor(SchemaOwnership $ownership, ?string $prefix): SchemaOwnership
    {
        return $prefix !== null && $prefix !== '' && $ownership instanceof ScopedSchemaOwnership
            ? $ownership->forPrefix($prefix)
            : $ownership;
    }

    /** @param class-string ...$providers */
    public static function registerProviders(Application $app, string ...$providers): void
    {
        foreach ($providers as $provider) {
            if (! is_subclass_of($provider, SchemaSetProvider::class)) {
                throw new InvalidArgumentException("{$provider} must implement ".SchemaSetProvider::class.'.');
            }

            if (! self::markProviderTagged($app, $provider)) {
                continue;
            }

            $app->singletonIf($provider);
            $app->tag([$provider], SchemaSetProvider::class);
        }
    }

    /**
     * Binds a transactional {@see ModuleMigrationBoundary} fallback for standalone code-plugin packages
     * declaring {@see SchemaLifecycle::Module} schema sets without `packman-modules`.
     */
    public static function registerExtensionModuleBoundary(Application $app): void
    {
        $app->singletonIf(ModuleMigrationBoundary::class, static fn (): ModuleMigrationBoundary => new class implements ModuleMigrationBoundary
        {
            public function run(Connection $connection, string $connectionName, array $owners, Closure $run): void
            {
                $connection->transaction($run);
            }
        });
    }

    private static function markProviderTagged(Application $app, string $provider): bool
    {
        /** @var WeakMap<Application, array<string, true>>|null $tagged */
        static $tagged = null;

        if (! $tagged instanceof WeakMap) {
            /** @var WeakMap<Application, array<string, true>> $map */
            $map = new WeakMap;
            $tagged = $map;
        }

        $entries = $tagged->offsetExists($app) ? $tagged->offsetGet($app) : [];

        if (isset($entries[$provider])) {
            return false;
        }

        $entries[$provider] = true;
        $tagged->offsetSet($app, $entries);

        return true;
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
