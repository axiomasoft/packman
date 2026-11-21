<?php

declare(strict_types=1);

namespace Packman\Modules\Internal\Commands;

use Illuminate\Console\Command;
use Packman\Attributes\Internal;
use Packman\Modules\Contracts\Lifecycle\ModuleLifecycle;

/**
 * Reconciles a module's ownership registry with the real schema and provides the single legal
 * exit from the terminal `purged` state — a thin wrapper over ModuleLifecycle::repair().
 *
 * @internal
 */
#[Internal]
final class RepairModuleCommand extends Command
{
    protected $signature = 'modules:repair {module : Composer name of the module to repair}';

    protected $description = 'Reconcile mod_records with the schema and reset a purged module to available.';

    public function handle(ModuleLifecycle $lifecycle): int
    {
        $rawModule = $this->input->getArgument('module');
        $module = is_string($rawModule) ? trim($rawModule) : '';

        if (preg_match('/^[a-z0-9][a-z0-9._-]*\/[a-z0-9][a-z0-9._-]*$/', $module) !== 1) {
            $this->error('Module name must be a Composer package name.');

            return self::INVALID;
        }

        $lifecycle->repair($module);
        $this->info(sprintf('Reconciled module [%s] with the schema.', $module));

        return self::SUCCESS;
    }
}
