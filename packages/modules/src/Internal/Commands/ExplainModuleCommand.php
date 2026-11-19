<?php

declare(strict_types=1);

namespace Packman\Modules\Internal\Commands;

use Illuminate\Console\Command;
use Packman\Attributes\Internal;
use Packman\Context\Contracts\ContextScopeInterface;
use Packman\Context\Data\ScopeRef;
use Packman\Modules\Contracts\Registry\RegistryIntrospection;

/**
 * Read-only local operator diagnostic for module activation status.
 */
#[Internal]
final class ExplainModuleCommand extends Command
{
    protected $signature = 'modules:why
                            {module : Composer package name of the module}
                            {--json : Emit machine-readable JSON}
                            {--scope= : Trusted scope id for tenant verdict}
                            {--account= : Alias for --scope}';

    protected $description = 'Explain why a module is or is not active for the current installation.';

    public function handle(RegistryIntrospection $introspection): int
    {
        $rawModule = $this->argument('module');
        $module = is_string($rawModule) ? $rawModule : '';
        $context = $this->resolveContext();
        $explanation = $introspection->why(module: $module, context: $context);

        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'schemaVersion' => $explanation->schemaVersion,
                'module' => $explanation->module,
                'status' => $explanation->status,
                'declaredConstraints' => $explanation->declaredConstraints,
                'reasons' => $explanation->reasons,
                'hasTenantVerdict' => $explanation->hasTenantVerdict,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->line(sprintf('Module: %s', $explanation->module));
        $this->line(sprintf('Status: %s', $explanation->status));
        $this->line(sprintf('Tenant verdict: %s', $explanation->hasTenantVerdict ? 'yes' : 'no'));

        if (! $explanation->hasTenantVerdict) {
            $this->warn('No trusted tenant context supplied; activation verdict is declaration-only.');
        }

        $this->line('Reasons: '.implode(', ', $explanation->reasons));

        return self::SUCCESS;
    }

    private function resolveContext(): ?ContextScopeInterface
    {
        $scopeId = $this->option('scope') ?? $this->option('account');

        if (! is_string($scopeId) || $scopeId === '') {
            return null;
        }

        return new ScopeRef(
            id: $scopeId,
            attributes: [
                'slug' => $scopeId,
                'status' => 'active',
            ],
        );
    }
}
