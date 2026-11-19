<?php

declare(strict_types=1);

namespace Packman\Modules\Internal\Registry;

use Closure;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Packman\Modules\Contracts\Registry\ModuleRegistryCache;
use Packman\Modules\Internal\Support\ModulesOptions;

/**
 * {@see ModuleRegistryCache} over any {@see CacheRepository} — deliberately
 * WITHOUT `tags()`, so it works on the default `file`/`database` store and
 * in the boxed profile. Store, TTL and prefix come from {@see ModulesOptions}.
 *
 * @internal spec: D30/A1/A2, A45/A46
 */
final readonly class RepositoryModuleRegistryCache implements ModuleRegistryCache
{
    /** @param CacheRepository|(Closure(): CacheRepository) $store */
    public function __construct(
        private CacheRepository|Closure $store,
        private ModulesOptions $options,
    ) {}

    public function rememberEnabled(string $accountId, int $version, Closure $resolver): array
    {
        // Epoch-stamped key: {prefix}:account:{id}:v{version}:enabled.
        // A bumped version routes to a fresh key — the stale one is left to
        // expire on its own TTL, so no cross-node forget() is needed. NO
        // tags(): the account id already namespaces the key.
        $key = sprintf('%s:account:%s:v%d:enabled', $this->options->cachePrefix, $accountId, $version);

        $store = $this->store instanceof Closure ? ($this->store)() : $this->store;

        return $store->remember($key, $this->options->cacheTtl, $resolver);
    }
}
