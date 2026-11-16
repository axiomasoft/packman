<?php

declare(strict_types=1);

namespace Packman\Modules\Internal\Registry;

use Packman\Context\Contracts\ContextScopeInterface;
use Packman\Modules\Contracts\Registry\ModuleStateReader;

/**
 * Fake reader for tests and the boxed default — no database. The
 * DB-backed reader (`SELECT name,state,edition FROM mod_modules`) is the
 * production counterpart.
 *
 * @internal spec: P1.5, P1.6
 */
final readonly class ArrayModuleStateReader implements ModuleStateReader
{
    /**
     * @param  array<string, array<string, array{state: string, edition: string}>>  $statesByAccount
     *                                                                                                keyed by account id, then module composer name.
     */
    public function __construct(
        private array $statesByAccount = [],
    ) {}

    public function enabledFor(ContextScopeInterface $ctx): array
    {
        return $this->statesByAccount[$ctx->id()] ?? [];
    }
}
