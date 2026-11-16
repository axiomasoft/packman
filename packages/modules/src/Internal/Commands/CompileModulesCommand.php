<?php

declare(strict_types=1);

namespace Packman\Modules\Internal\Commands;

use Illuminate\Console\Command;
use Packman\Modules\Data\CompiledModule;
use Packman\Modules\Exceptions\CompilationException;
use Packman\Modules\Exceptions\InvalidManifestException;
use Packman\Modules\Internal\Compiler\RegistryCompiler;
use Packman\Modules\Internal\Discovery\ModuleDiscovery;
use Packman\Modules\Internal\Registry\CompiledModuleRegistry;
use Packman\Modules\Internal\Support\ModulesOptions;
use Packman\Modules\ModulesServiceProvider;

/**
 * Deploy-hook compile pipeline: discovers module manifests, validates
 * them, applies the closed extender set in memory, then writes both the
 * per-module metadata and the full {@see CompiledRegistry} (all 10 slices)
 * atomically to `bootstrap/cache/modules.php` (tmp+rename) for
 * {@see CompiledModuleRegistry} to read on the hot path. Wired into
 * `php artisan optimize` (deploy) by {@see ModulesServiceProvider}.
 *
 * @internal spec: B-10 §5.2, §7.4 п.7, P1.15
 */
final class CompileModulesCommand extends Command
{
    protected $signature = 'modules:compile';

    protected $description = 'Discover module manifests, validate and compile their extenders.';

    public function handle(ModuleDiscovery $discovery, RegistryCompiler $compiler, ?ModulesOptions $options = null): int
    {
        try {
            $manifests = $discovery->discover();
        } catch (InvalidManifestException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf('Discovered %d module manifest(s).', count($manifests)));

        try {
            $compiled = $compiler->compile($manifests);
        } catch (CompilationException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf('Compiled %d entity definition(s).', count($compiled->entities)));

        $relativeCachePath = CompiledModuleRegistry::CACHE_PATH;

        if ($options !== null) {
            $relativeCachePath = $options->cachePath;
        }

        $cachePath = $this->laravel->basePath($relativeCachePath);
        CompiledModuleRegistry::writeAtomic(
            path: $cachePath,
            modules: array_map(CompiledModule::fromManifest(...), $manifests),
            registry: $compiled,
        );

        $this->components->info(sprintf('Wrote compiled registry to %s.', $cachePath));

        return self::SUCCESS;
    }
}
