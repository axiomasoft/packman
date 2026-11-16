<?php

declare(strict_types=1);

namespace Packman\Modules\Laravel;

use Illuminate\Console\Application as Console;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Queue\Events\JobProcessing;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Foundation\Contracts\PackageRegistrar;
use Packman\Modules\Contracts\EntityRegistry;
use Packman\Modules\Contracts\EntityRegistryHook;
use Packman\Modules\Contracts\ModuleRegistry;
use Packman\Modules\Internal\Commands\CompileModulesCommand;
use Packman\Modules\Internal\Commands\ExplainModuleCommand;
use Packman\Modules\Internal\Commands\RepairModuleCommand;
use Packman\Modules\Internal\InMemoryEntityRegistry;
use Packman\Modules\Internal\Registry\CompiledModuleRegistry;
use Packman\Modules\Internal\Support\ModulesOptions;

#[Spi(kind: SpiKind::Call, since: '1.0')]
final class ModulesBootRegistrar implements PackageRegistrar
{
    #[Override]
    public function register(Application $app): void {}

    #[Override]
    public function boot(Application $app): void
    {
        if ($app->runningInConsole()) {
            Console::starting(self::registerCommands(...));
        }

        // Epoch memo reset: a long-running queue worker drops its
        // per-process compiled-file memo before each job.
        $app->make(Dispatcher::class)->listen(JobProcessing::class, static fn () => self::flushRegistry($app));

        // Built after every module provider has booted and registered its entities.
        $app->booted(fn () => $this->projectEntities($app));
    }

    private static function registerCommands(Console $artisan): void
    {
        $artisan->resolveCommands([
            CompileModulesCommand::class,
            ExplainModuleCommand::class,
            RepairModuleCommand::class,
        ]);
    }

    private static function flushRegistry(Application $app): void
    {
        $registry = self::moduleRegistry($app);

        if ($registry instanceof CompiledModuleRegistry) {
            $registry->flush();
        }
    }

    private static function moduleRegistry(Application $app): ModuleRegistry
    {
        return $app->make(ModuleRegistry::class);
    }

    private function projectEntities(Application $app): void
    {
        if ($this->isCompilingModules($app)) {
            return;
        }

        $arrayRegistry = $app->make(InMemoryEntityRegistry::class);
        $compiled = $app->make(ModuleRegistry::class)->compiled();

        foreach ($compiled->entities as $definition) {
            $arrayRegistry->register($definition);
        }

        $registry = $app->make(EntityRegistry::class);
        $map = [];

        // Morph map spans ALL entities regardless of active module filter.
        foreach ($registry->all() as $definition) {
            assert(is_a($definition->model, Model::class, true));
            $map[$definition->handle] = $definition->model;
        }

        if ($map !== []) {
            Relation::morphMap($map);
        }

        foreach ($app->tagged(EntityRegistryHook::class) as $hook) {
            if ($hook instanceof EntityRegistryHook) {
                $hook->wire($registry);
            }
        }
    }

    private function isCompilingModules(Application $app): bool
    {
        $compileCommand = $app->make(ModulesOptions::class)->compileCommand;

        return $app->runningInConsole()
            && (is_array($_SERVER['argv'] ?? null) ? ($_SERVER['argv'][1] ?? null) : null) === $compileCommand;
    }
}
