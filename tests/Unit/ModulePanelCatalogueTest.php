<?php

declare(strict_types=1);

use Packman\Context\Data\ScopeRef;
use Packman\Modules\Contracts\Entities\EntityRegistry;
use Packman\Modules\Contracts\Registry\ModuleRegistry;
use Packman\Modules\Data\Entities\EntityDefinition;
use Packman\Modules\Enums\Entities\Capability;
use Packman\Panels\Contracts\Registry\PanelCatalogue;
use Packman\Panels\Contracts\Registry\PanelProvider;
use Packman\Panels\Data\Admission\PanelAccess;
use Packman\Panels\Data\Panel\PanelContext;
use Packman\Panels\Data\Panel\PanelDescriptor;
use Packman\Panels\Data\Panel\PanelReference;
use Packman\Panels\Data\Subjects\EntityReference;
use Packman\Panels\Internal\Catalogue\InMemoryPanelCatalogue;
use Packman\Panels\Internal\Catalogue\ModulePanelCatalogue;

function modPanelContext(string $account = 'account-a', string $actorKind = 'guest', ?string $actorId = null): PanelContext
{
    return new PanelContext(new ScopeRef($account), $actorKind, $actorId);
}

/**
 * @param  list<Capability>  $capabilities
 */
function modPanelEntity(string $handle, array $capabilities): EntityDefinition
{
    return new EntityDefinition(
        handle: $handle,
        model: stdClass::class,
        table: str_replace('.', '_', $handle),
        module: 'example/'.str_replace('.', '-', $handle),
        labelSingular: 'Example',
        labelPlural: 'Examples',
        capabilities: $capabilities,
        fields: [],
        titleAttribute: null,
    );
}

/**
 * @param  array<string, EntityDefinition>  $definitions
 * @param  array<string, list<EntityDefinition>>  $active
 */
function modPanelsRegistry(array $definitions, array &$active): EntityRegistry
{
    $registry = Mockery::mock(EntityRegistry::class);
    $registry->shouldReceive('has')->andReturnUsing(static fn (string $handle): bool => isset($definitions[$handle]));
    $registry->shouldReceive('get')->andReturnUsing(static fn (string $handle): EntityDefinition => $definitions[$handle]);
    $registry->shouldReceive('all')->andReturnUsing(static function (ScopeRef $context) use (&$active): array {
        return $active[$context->id()] ?? [];
    });

    return $registry;
}

/**
 * @param  array<string, bool>  $active
 */
function modPanelsModules(array &$active): ModuleRegistry
{
    $modules = Mockery::mock(ModuleRegistry::class);
    $modules->shouldReceive('isActive')->andReturnUsing(static function (string $module, ScopeRef $context) use (&$active): bool {
        return $active[$context->id()] ?? false;
    });

    return $modules;
}

/**
 * @param  list<string>  $supportedActions
 */
function modPanelProvider(string $key, ?string $requiredCapability, Closure $policy, array $supportedActions = ['open']): PanelProvider
{
    return new readonly class($key, $requiredCapability, $policy, $supportedActions) implements PanelProvider
    {
        /** @param list<string> $supportedActions */
        public function __construct(
            private string $key,
            private ?string $requiredCapability,
            private Closure $policy,
            private array $supportedActions,
        ) {}

        public function descriptor(): PanelDescriptor
        {
            return new PanelDescriptor(
                version: 'panel/1',
                key: $this->key,
                owningModule: 'example/panels',
                requiredCapability: $this->requiredCapability,
                supportedActions: $this->supportedActions,
                label: 'example.panels.'.$this->key,
            );
        }

        public function access(PanelContext $context, PanelReference $panel): PanelAccess
        {
            return ($this->policy)($context, $panel);
        }
    };
}

it('discovers only supported descriptors across active entities', function (): void {
    $note = modPanelEntity('example.note', [Capability::Auditable]);
    $asset = modPanelEntity('example.asset', [Capability::Documentable]);
    $active = ['account-a' => [$note, $asset]];
    $modules = ['account-a' => true];
    $allow = static fn (): PanelAccess => new PanelAccess(view: true, actions: ['open'], reason: null);

    $catalogue = new ModulePanelCatalogue([
        modPanelProvider('example.audit', 'auditable', $allow),
        modPanelProvider('example.documents', 'documentable', $allow),
    ], modPanelsRegistry(['example.note' => $note, 'example.asset' => $asset], $active), modPanelsModules($modules));

    expect(array_map(static fn (PanelDescriptor $d): string => $d->key, $catalogue->forEntity(modPanelContext(), new EntityReference('example.note', 'n-1'))))->toBe(['example.audit'])
        ->and(array_map(static fn (PanelDescriptor $d): string => $d->key, $catalogue->forEntity(modPanelContext(), new EntityReference('example.asset', 'a-1'))))->toBe(['example.documents']);
});

it('denies unknown entity, provider, panel and unavailable policy', function (): void {
    $note = modPanelEntity('example.note', [Capability::Auditable]);
    $active = ['account-a' => [$note]];
    $modules = ['account-a' => true];
    $policy = static function (PanelContext $context, PanelReference $panel): PanelAccess {
        if ($panel->panelId !== 'overview') {
            return PanelAccess::deny('panel_unavailable');
        }

        throw new RuntimeException('policy dependency unavailable');
    };

    $catalogue = new ModulePanelCatalogue(
        [modPanelProvider('example.audit', 'auditable', $policy)],
        modPanelsRegistry(['example.note' => $note], $active),
        modPanelsModules($modules),
    );

    expect($catalogue->forEntity(modPanelContext(), new EntityReference('example.unknown', 'x')))->toBe([])
        ->and($catalogue->access(modPanelContext(), new PanelReference('example.unknown', 'overview', null))->reason)->toBe('provider_unavailable')
        ->and($catalogue->access(modPanelContext(), new PanelReference('example.audit', 'missing', null))->reason)->toBe('panel_unavailable')
        ->and($catalogue->access(modPanelContext(), new PanelReference('example.audit', 'overview', null))->reason)->toBe('policy_unavailable');
});

it('fails closed when a provider returns an action outside its registered descriptor', function (): void {
    $note = modPanelEntity('example.note', [Capability::Auditable]);
    $active = ['account-a' => [$note]];
    $modules = ['account-a' => true];
    $catalogue = new ModulePanelCatalogue([
        modPanelProvider('example.audit', 'auditable', static fn (): PanelAccess => new PanelAccess(true, ['write'], null)),
    ], modPanelsRegistry(['example.note' => $note], $active), modPanelsModules($modules));

    expect($catalogue->access(modPanelContext(), new PanelReference('example.audit', 'overview', null))->reason)->toBe('invalid_policy_result');
});

it('hides disabled module providers without evaluating policy at boot and revokes on state change', function (): void {
    $note = modPanelEntity('example.note', [Capability::Auditable]);
    $active = ['account-a' => [$note]];
    $modules = ['account-a' => false];
    $policyCalls = 0;

    $catalogue = new ModulePanelCatalogue([
        modPanelProvider('example.audit', 'auditable', static function () use (&$policyCalls): PanelAccess {
            $policyCalls++;

            return new PanelAccess(view: true, actions: ['open'], reason: null);
        }),
    ], modPanelsRegistry(['example.note' => $note], $active), modPanelsModules($modules));

    expect($catalogue->forEntity(modPanelContext(), new EntityReference('example.note', 'n-1')))->toBe([])
        ->and($policyCalls)->toBe(0);

    $modules['account-a'] = true;
    $reference = new PanelReference('example.audit', 'overview', new EntityReference('example.note', 'n-1'));
    expect($catalogue->access(modPanelContext(), $reference)->view)->toBeTrue();

    $active['account-a'] = [];
    expect($catalogue->access(modPanelContext(), $reference)->reason)->toBe('entity_unavailable');
});

it('works standalone via InMemoryPanelCatalogue without ModuleRegistry', function (): void {
    $allow = static fn (): PanelAccess => new PanelAccess(view: true, actions: ['open'], reason: null);
    $catalogue = new InMemoryPanelCatalogue([
        modPanelProvider('standalone.panel', null, $allow),
        modPanelProvider('capable.panel', 'auditable', $allow),
    ]);

    expect($catalogue)->toBeInstanceOf(PanelCatalogue::class)
        ->and(array_map(static fn (PanelDescriptor $d): string => $d->key, $catalogue->forEntity(modPanelContext(), new EntityReference('any.entity', '1'))))->toBe(['standalone.panel', 'capable.panel'])
        ->and($catalogue->access(modPanelContext(), new PanelReference('standalone.panel', 'overview', null))->view)->toBeTrue();
});

it('rejects duplicate provider keys and invalid DTO boundaries', function (): void {
    $provider = modPanelProvider('example.audit', null, static fn (): PanelAccess => PanelAccess::deny('no'));

    expect(fn (): InMemoryPanelCatalogue => new InMemoryPanelCatalogue([$provider, $provider]))->toThrow(LogicException::class, 'Duplicate panel provider key')
        ->and(fn (): PanelContext => modPanelContext(actorKind: 'user'))->toThrow(InvalidArgumentException::class)
        ->and(fn (): PanelContext => new PanelContext(modPanelContext()->tenant, 'guest', 'forged'))->toThrow(InvalidArgumentException::class)
        ->and(fn (): PanelAccess => new PanelAccess(false, ['open'], null))->toThrow(InvalidArgumentException::class)
        ->and(fn (): PanelDescriptor => new PanelDescriptor('panel/2', 'x', 'x', null, [], 'x'))->toThrow(InvalidArgumentException::class)
        ->and(fn (): EntityReference => new EntityReference('', 'n-1'))->toThrow(InvalidArgumentException::class)
        ->and(fn (): PanelReference => new PanelReference('', 'overview', null))->toThrow(InvalidArgumentException::class);
});
