<?php

declare(strict_types=1);

namespace Packman\Modules\Laravel;

use Illuminate\Console\Events\ArtisanStarting;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Queue\Events\JobProcessing;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Foundation\Contracts\PackageRegistrar;
use Packman\Modules\Contracts\Entities\EntityRegistry;
use Packman\Modules\Contracts\Entities\EntityRegistryHook;
use Packman\Modules\Contracts\Registry\ModuleRegistry;
use Packman\Modules\Internal\Commands\CompileModulesCommand;
use Packman\Modules\Internal\Commands\ExplainModuleCommand;
use Packman\Modules\Internal\Commands\RepairModuleCommand;
use Packman\Modules\Internal\Entities\InMemoryEntityRegistry;
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
            $app->make(Dispatcher::class)->listen(ArtisanStarting::class, self::registerCommands(...));
        }

        // Epoch memo reset: a long-running queue worker drops its
        // per-process compiled-file memo before each job.
        $app->make(Dispatcher::class)->listen(JobProcessing::class, static fn () => self::flushRegistry($app));

        // Built after every module provider has booted and registered its entities.
        $app->booted(fn () => $this->projectEntities($app));
    }

    private static function registerCommands(ArtisanStarting $event): void
    {
        $event->artisan->resolveCommands([
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
        if (! $app->runningInConsole()) {
            return false;
        }

        $command = self::consoleCommandName();

        if ($command === null) {
            return false;
        }

        $compileCommand = $app->make(ModulesOptions::class)->compileCommand;

        return in_array($command, [
            $compileCommand,
            'optimize',
            'package:discover',
            'config:cache',
            'route:cache',
            'view:cache',
            'event:cache',
        ], true);
    }

    private static function consoleCommandName(): ?string
    {
        $rawArgv = $_SERVER['argv'] ?? null;

        if (! is_array($rawArgv)) {
            return null;
        }

        $argv = array_values($rawArgv);
        $count = count($argv);

        for ($index = 1; $index < $count; $index++) {
            $token = $argv[$index];

            if (! is_string($token) || $token === '') {
                continue;
            }

            if ($token === '--') {
                $next = $argv[$index + 1] ?? null;

                return is_string($next) && $next !== '' ? $next : null;
            }

            if ($token === '--env') {
                $next = $argv[$index + 1] ?? null;

                if (is_string($next) && ! str_starts_with($next, '-')) {
                    $index++;
                }

                continue;
            }

            if (str_starts_with($token, '-')) {
                continue;
            }

            return $token;
        }

        return null;
    }
}
