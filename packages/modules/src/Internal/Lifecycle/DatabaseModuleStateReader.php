<?php

declare(strict_types=1);

namespace Packman\Modules\Internal\Lifecycle;

use Illuminate\Database\ConnectionResolverInterface;
use Packman\Context\Contracts\ContextScopeInterface;
use Packman\Modules\Contracts\Registry\ModuleStateReader;
use Packman\Modules\Enums\Lifecycle\ModuleState;
use stdClass;

/**
 * DB-backed {@see ModuleStateReader}: reads the enabled set straight from
 * `mod_modules`. `$ctx` allows per-tenant/account connection or scoping.
 * `$connection` is null (app default) unless a caller pins an explicit name.
 *
 * @internal spec: P1.5, D13
 */
final readonly class DatabaseModuleStateReader implements ModuleStateReader
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private ?string $connection = null,
        private string $modulesTable = 'mod_modules',
    ) {}

    /** @return array<string, array{state: string, edition: string}> */
    public function enabledFor(ContextScopeInterface $ctx): array
    {
        return $this->connections->connection($this->connection)->table($this->modulesTable)
            ->where('state', ModuleState::Enabled->value)
            ->get(['name', 'state', 'edition'])
            ->mapWithKeys(static function (stdClass $row): array {
                assert(is_string($row->name) && is_string($row->state) && is_string($row->edition));

                return [$row->name => ['state' => $row->state, 'edition' => $row->edition]];
            })
            ->all();
    }
}
