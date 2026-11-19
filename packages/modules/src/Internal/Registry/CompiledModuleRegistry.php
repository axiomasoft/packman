<?php

declare(strict_types=1);

namespace Packman\Modules\Internal\Registry;

use Closure;
use Packman\Attributes\Internal;
use Packman\Context\Contracts\ContextScopeInterface;
use Packman\Modules\Contracts\Registry\ModuleRegistry;
use Packman\Modules\Contracts\Registry\ModuleRegistryCache;
use Packman\Modules\Contracts\Registry\ModuleStateReader;
use Packman\Modules\Contracts\Registry\VersionedModuleStateReader;
use Packman\Modules\Data\Compiler\CompiledModule;
use Packman\Modules\Data\Compiler\CompiledRegistry;
use Packman\Modules\Exceptions\Registry\ModuleNotFound;
use Packman\Modules\ModulesServiceProvider;
use RuntimeException;

/**
 * File-backed {@see ModuleRegistry}. `all()`/`get()` read the compiled file
 * written by `modules:compile` (opcache after the first `require`, 0
 * fs scans on later calls); `active()` intersects it with a per-account
 * cache backed by {@see ModuleStateReader} — a cache miss costs exactly one
 * reader call, a warm cache costs none but the epoch read.
 *
 * Cache invalidation is by EPOCH: `$versionResolver` returns a monotonic
 * per-account registry version (the count of applied lifecycle transitions),
 * stamped into the cache key via {@see ModuleRegistryCache}. A bumped
 * version routes reads to a fresh key, so enable/disable is visible to OTHER
 * processes and nodes without any `forget()` — the file-cache /
 * long-running-worker failure mode of a caller-local clear cannot happen. No
 * cache tags anywhere.
 *
 * The same file also carries the full {@see CompiledRegistry} (all 8 slices)
 * under a `registry` key; {@see compiled()} reads and memoizes it for the
 * runtime EntityRegistry projection and the morph map.
 *
 * A missing compiled file is a hard error under `$failIfMissing` (production
 * deploy that forgot `php artisan modules:compile` / `optimize`) —
 * never a silent empty registry, which would resurrect the same defect class
 * quietly.
 *
 * Publication is one request/job snapshot. `JobProcessing` may flush the
 * memo before a job; the first `compiled()` pins that artifact for the job.
 * Octane and long-lived workers do not hot-reload mid-request: deploy must
 * run `octane:reload` and restart queue workers. Tenant activation epochs
 * never rewrite this fingerprint.
 *
 * @internal spec: B-10 §5.2/§7.1, AC-3/AC-4, D39, D30/A1/A2, P1.15, A10
 */
#[Internal]
final class CompiledModuleRegistry implements ModuleRegistry
{
    /** @var array<string, CompiledModule>|null */
    private ?array $modules = null;

    private ?CompiledRegistry $registry = null;

    /**
     * @param  ModuleStateReader|(Closure(): ModuleStateReader)  $stateReader
     * @param  ModuleRegistryCache|(Closure(): ModuleRegistryCache)  $cache
     * @param  (Closure(ContextScopeInterface):int)|null  $versionResolver  Monotonic per-account registry epoch.
     *
     * @internal spec: D39
     */
    public function __construct(
        private readonly string $cachePath,
        private readonly ModuleStateReader|Closure $stateReader,
        private readonly ModuleRegistryCache|Closure $cache,
        private readonly ?Closure $versionResolver = null,
        private readonly bool $failIfMissing = false,
        private readonly string $compileCommand = 'modules:compile',
    ) {}

    /** Compiled registry location, relative to the application base path. */
    public const string CACHE_PATH = 'bootstrap/cache/modules.php';

    public function all(): array
    {
        return array_values($this->modules());
    }

    public function get(string $name): CompiledModule
    {
        return $this->modules()[$name] ?? throw new ModuleNotFound($name);
    }

    public function active(ContextScopeInterface $ctx): array
    {
        $enabled = $this->enabledFor($ctx);

        return array_values(array_filter(
            $this->modules(),
            static fn (CompiledModule $module): bool => array_key_exists($module->composerName, $enabled),
        ));
    }

    public function isActive(string $name, ContextScopeInterface $ctx): bool
    {
        // A45/A46: active iff enabled AND still present in the compiled
        // registry. A row left in mod_modules for a module whose code no
        // longer ships (composer remove / rename) must NOT report active —
        // isActive() is the EntityRegistry activity predicate, and a later
        // get() on that name would throw ModuleNotFound.
        return array_key_exists($name, $this->enabledFor($ctx))
            && array_key_exists($name, $this->modules());
    }

    public function compiled(): CompiledRegistry
    {
        if ($this->registry !== null) {
            return CompiledRegistry::fromArray($this->registry->toArray());
        }

        $data = $this->readCache();

        if ($data === [] && ! $this->failIfMissing && ! is_file($this->cachePath)) {
            return $this->registry = CompiledRegistry::empty();
        }

        /** @var array<string, mixed> $registry */
        $registry = is_array($data['registry'] ?? null) ? $data['registry'] : [];

        return $this->registry = CompiledRegistry::fromArray($registry);
    }

    /**
     * Drop the per-process memo of the compiled file: a long-running
     * `queue:work` must re-read a redeploy's recompiled registry on the next
     * job instead of serving hours-old code state. Wired to `JobProcessing` in
     * {@see ModulesServiceProvider}. The enabled-set is NOT
     * memoized in a property — it lives in the epoch-keyed cache and is
     * refreshed against the DB epoch on every read, so worker freshness does
     * not depend on this call alone.
     *
     * @internal spec: D39
     */
    public function flush(): void
    {
        $this->modules = null;
        $this->registry = null;
    }

    /** @return array<string, array{state: string, edition: string}> */
    private function enabledFor(ContextScopeInterface $ctx): array
    {
        $reader = $this->stateReader instanceof Closure ? ($this->stateReader)() : $this->stateReader;
        $version = $this->versionResolver !== null
            ? ($this->versionResolver)($ctx)
            : ($reader instanceof VersionedModuleStateReader ? $reader->versionFor($ctx) : null);

        if ($version === null) {
            return $reader->enabledFor($ctx);
        }

        $cache = $this->cache instanceof Closure ? ($this->cache)() : $this->cache;

        return $cache->rememberEnabled(
            accountId: $ctx->id(),
            version: $version,
            resolver: static fn (): array => $reader->enabledFor($ctx),
        );
    }

    /** @return array<string, CompiledModule> */
    private function modules(): array
    {
        if ($this->modules !== null) {
            return $this->modules;
        }

        $data = $this->readCache();

        $modules = [];

        /** @var list<array<string, mixed>> $rows */
        $rows = is_array($data['modules'] ?? null) ? $data['modules'] : [];

        foreach ($rows as $row) {
            $module = CompiledModule::fromArray($row);
            $modules[$module->composerName] = $module;
        }

        return $this->modules = $modules;
    }

    /**
     * Read and decode the compiled file once. Missing file is a hard failure
     * in production (`$failIfMissing`) — a silent empty payload would ship a
     * runtime with no entities and no morph map, exactly the class of defect
     * A10 was, only quieter.
     *
     * @return array<array-key, mixed>
     */
    private function readCache(): array
    {
        if (! is_file($this->cachePath)) {
            if ($this->failIfMissing) {
                throw new RuntimeException(sprintf(
                    'Compiled module registry not found at [%s]. Run `php artisan %s` (or `php artisan optimize`) as part of the deploy.',
                    $this->cachePath,
                    $this->compileCommand,
                ));
            }

            return [];
        }

        /** @var mixed $data */
        $data = require $this->cachePath;

        return is_array($data) ? $data : [];
    }

    /** @param  list<CompiledModule>  $modules */
    public static function writeAtomic(string $path, array $modules, ?CompiledRegistry $registry = null): void
    {
        $payload = [
            'modules' => array_map(static fn (CompiledModule $module): array => $module->toArray(), $modules),
            'registry' => ($registry ?? CompiledRegistry::empty())->toArray(),
        ];

        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, recursive: true) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create compiled registry directory [%s].', $directory));
        }

        $tmpPath = $path.'.'.bin2hex(random_bytes(8)).'.tmp';

        // tmp+rename keeps the swap atomic; an unchecked
        // partial write or a failed rename would drop a corrupt file over the
        // live one, so both return values are verified. The fs calls are
        // @-silenced so a warning is not converted to a foreign exception
        // (ErrorException) ahead of these explicit, typed failures.
        if (@file_put_contents($tmpPath, "<?php\n\nreturn ".var_export($payload, true).";\n", LOCK_EX) === false) {
            @unlink($tmpPath);

            throw new RuntimeException(sprintf('Unable to write temporary compiled registry [%s].', $tmpPath));
        }

        if (! @rename($tmpPath, $path)) {
            @unlink($tmpPath);

            throw new RuntimeException(sprintf('Unable to move compiled registry into place [%s].', $path));
        }
    }
}
