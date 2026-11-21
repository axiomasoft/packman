<?php

declare(strict_types=1);

namespace Packman\Modules\Internal\Commands;

use Illuminate\Console\Command;
use Packman\Attributes\Internal;
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
        [$scopeId, $conflict] = $this->scopeSelection();

        if ($conflict) {
            $this->error('--scope and --account name different tenants.');

            return self::INVALID;
        }

        $context = $scopeId === null ? null : new ScopeRef(
            id: $scopeId,
            attributes: [
                'slug' => $scopeId,
                'status' => 'active',
            ],
        );
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

    /**
     * A blank option is absent. When both names are present they must agree.
     *
     * @return array{0: ?string, 1: bool}
     */
    private function scopeSelection(): array
    {
        $scope = $this->presentOption('scope');
        $account = $this->presentOption('account');

        if ($scope !== null && $account !== null && $scope !== $account) {
            return [null, true];
        }

        return [$scope ?? $account, false];
    }

    private function presentOption(string $name): ?string
    {
        $value = $this->option($name);

        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
