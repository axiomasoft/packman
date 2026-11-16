<?php

declare(strict_types=1);

namespace Packman\Errors\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Errors\Contracts\ProblemDefinitionProvider;
use Packman\Errors\Contracts\ProblemRegistry;
use Packman\Errors\Enums\CoreErrorCode;
use Packman\Errors\Exceptions\ProblemRegistryException;
use Packman\Errors\Internal\ProblemContribution;
use ReflectionClass;

/** Register during the owning service provider's register(), before the catalogue freezes at boot. */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class ProblemDefinitions
{
    /** @param class-string<ProblemDefinitionProvider> $provider */
    public static function register(Application $app, string $composerManifest, string $provider): void
    {
        $binding = ProblemContribution::class.':'.$provider;

        if ($app->bound($binding)) {
            return;
        }

        if ($app->resolved(ProblemRegistry::class)) {
            throw new ProblemRegistryException(errorCode: CoreErrorCode::RegistryFrozen, diagnostic: 'ERROR-REGISTRY-FROZEN');
        }

        $manifest = json_decode((string) file_get_contents($composerManifest), true, flags: JSON_THROW_ON_ERROR);
        $package = is_array($manifest) ? ($manifest['name'] ?? null) : null;
        $autoload = is_array($manifest) ? ($manifest['autoload'] ?? []) : [];
        $prefixes = is_array($autoload) ? ($autoload['psr-4'] ?? []) : [];
        $extra = is_array($manifest) && is_array($manifest['extra'] ?? null) ? ($manifest['extra']['packman'] ?? null) : null;
        $owner = self::resolveOwner($package, is_array($extra) ? $extra : []);

        $owned = false;
        $sourceRoots = [];
        foreach (is_array($prefixes) ? $prefixes : [] as $prefix => $paths) {
            if (! is_string($prefix) || ! str_starts_with($provider, $prefix)) {
                continue;
            }
            $file = new ReflectionClass($provider)->getFileName();
            foreach ((array) $paths as $path) {
                $directory = is_string($path) ? realpath(dirname($composerManifest).'/'.$path) : false;

                if ($directory !== false) {
                    $sourceRoots[] = $directory;
                }
                $owned = $owned || ($file !== false && $directory !== false && str_starts_with($file, $directory.DIRECTORY_SEPARATOR));
            }
        }

        if (! is_string($owner) || ! is_string($package) || preg_match('/\A[a-z][a-z0-9]*(?:-[a-z0-9]+)*\z/D', $owner) !== 1 || ! $owned) {
            throw new ProblemRegistryException(errorCode: CoreErrorCode::ForeignPrefix, diagnostic: 'ERROR-FOREIGN-OWNER');
        }

        $app->singleton($binding, static function (Application $app) use ($owner, $provider, $package, $sourceRoots): ProblemContribution {
            $instance = $app->make($provider);

            if (! $instance instanceof ProblemDefinitionProvider) {
                throw new ProblemRegistryException(errorCode: CoreErrorCode::InvalidDefinition, diagnostic: 'ERROR-INVALID-PROVIDER');
            }

            return new ProblemContribution(owner: $owner, provider: $instance, package: $package, sourceRoots: $sourceRoots);
        });
        $app->tag([$binding], ProblemDefinitionProvider::class);
    }

    /** @param array<array-key, mixed> $extra */
    private static function resolveOwner(mixed $package, array $extra): ?string
    {
        if (is_string($extra['error-prefix'] ?? null) && $extra['error-prefix'] !== '') {
            return $extra['error-prefix'];
        }

        if (! is_string($package)) {
            return null;
        }

        if ($package === 'axiomasoft/packman') {
            return 'packman';
        }

        if (preg_match('~^[^/]+/packman-([a-z0-9-]+)$~', $package, $matches) === 1) {
            return $matches[1];
        }

        if (str_contains($package, '/')) {
            return explode('/', $package, 2)[1];
        }

        return null;
    }
}
