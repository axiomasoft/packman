<?php

declare(strict_types=1);

namespace PackmanEcosystemFixture;

use CoreX\Context\Data\AccountRef;
use CoreX\Context\Data\TenantContext;
use CoreX\Context\Data\WorkspaceRef;
use CoreX\Tenancy\Runtime\Contracts\AccountIsolationMapper;
use Illuminate\Support\ServiceProvider;
use LogicException;
use Tenant\Contracts\Context\ContextResolver;
use Tenant\Data\Context\ContextState;
use Tenant\Data\Context\FacetBindings;
use Tenant\Data\Context\IsolationContext;
use Tenant\Data\Context\IsolationIdentity;
use Tenant\Data\Context\Purpose;
use Tenant\Data\Context\ResolvedIsolation;
use Tenant\Data\Context\Selection;
use Tenant\Data\Verification\BindingExpectation;

final class HostResolver implements ContextResolver
{
    public function resolve(Selection $selection, Purpose $purpose): ResolvedIsolation
    {
        return new ResolvedIsolation(
            ContextState::active(new IsolationContext('single', $selection->identity, $selection->expectedProfile, new BindingExpectation($selection->identity->id, '1', '1'))),
            new FacetBindings([]),
        );
    }
}

final class DemoAccounts implements AccountIsolationMapper
{
    public function selection(AccountRef $account, ?WorkspaceRef $workspace = null): Selection
    {
        if (! in_array($account->id, ['demo-a', 'demo-b'], true) || $workspace !== null) {
            throw new LogicException('Unknown fixture account');
        }

        return Selection::single(new IsolationIdentity('org', $account->id));
    }

    public function context(ContextState $state): ?TenantContext
    {
        if ($state->kind !== 'active') {
            return null;
        }
        $id = $state->context->identity->id;

        return new TenantContext(new AccountRef($id, $id, 'active', [], []));
    }
}

final class FixtureServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app['config']->set('packman.schema.auto_load_migrations', false);
        $this->app['config']->set('cache.default', 'array');
        $this->app->singleton(ContextResolver::class, HostResolver::class);
        $this->app->singleton(AccountIsolationMapper::class, DemoAccounts::class);
    }
}
