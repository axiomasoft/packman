<?php

declare(strict_types=1);

namespace Packman\Modules\Internal\Registry;

use Override;
use Packman\Attributes\Internal;
use Packman\Context\Contracts\ContextScopeInterface;
use Packman\Modules\Contracts\Lifecycle\ModuleActivationGate;
use Packman\Modules\Contracts\Registry\ModuleRegistry;
use Packman\Modules\Contracts\Registry\RegistryIntrospection;
use Packman\Modules\Data\Compiler\CompiledEntityField;
use Packman\Modules\Data\Compiler\CompiledModule;
use Packman\Modules\Data\Compiler\CompiledRegistry;
use Packman\Modules\Data\Entities\Entity;
use Packman\Modules\Data\Entities\EntityDefinition;
use Packman\Modules\Data\Entities\EntityField;
use Packman\Modules\Data\Extenders\Api;
use Packman\Modules\Data\Extenders\Filament;
use Packman\Modules\Data\Extenders\Menu;
use Packman\Modules\Data\Extenders\Permissions;
use Packman\Modules\Data\Extenders\Routes;
use Packman\Modules\Data\Extenders\Workflow;
use Packman\Modules\Data\Registry\ModuleExplanation;
use Packman\Modules\Data\Registry\RegistryMap;
use Packman\Modules\Enums\Entities\Capability;
use Packman\Modules\Exceptions\Registry\InvalidRegistryPoint;
use Packman\Modules\Exceptions\Registry\ModuleNotFound;
use Packman\Modules\Exceptions\Registry\RegistryInputUnavailable;
use Packman\Modules\Exceptions\Registry\StaleRegistryRevision;
use Packman\Modules\Internal\Compiler\InstalledPackages;
use Packman\Modules\Internal\Compiler\PipelineStages;

/**
 * Read-only registry diagnostics over compiled module bytes and optional
 * tenant activation. Redacts internal class references and connection detail.
 *
 * @internal
 */
#[Internal]
final readonly class ReadRegistryIntrospection implements RegistryIntrospection
{
    public const string MAP_SCHEMA = 'registry-map/1';

    public const string EXPLANATION_SCHEMA = 'module-explanation/1';

    /** @var array<class-string, string> */
    private const array EXTENDER_NAMES = [
        Entity::class => 'entity',
        EntityField::class => 'entity_field',
        Permissions::class => 'permissions',
        Api::class => 'api',
        Filament::class => 'filament',
        Menu::class => 'menu',
        Routes::class => 'routes',
        Workflow::class => 'workflow',
    ];

    public function __construct(
        private ModuleRegistry $registry,
        private ModuleActivationGate $activationGate,
        private string $cachePath,
        private bool $failIfMissing = false,
        private ?string $installedJsonPath = null,
    ) {}

    #[Override]
    public function extensionPoints(): RegistryMap
    {
        $compiled = $this->compiledRegistry();
        $revision = self::revisionFor($compiled);

        return new RegistryMap(
            schemaVersion: self::MAP_SCHEMA,
            revision: $revision,
            extensionPoints: $this->buildExtensionPoints($compiled),
        );
    }

    #[Override]
    public function why(string $module, ?ContextScopeInterface $context): ModuleExplanation
    {
        $this->assertModuleName($module);

        $modules = $this->moduleIndex();
        $compiledModule = $modules[$module] ?? null;

        if ($compiledModule === null) {
            return new ModuleExplanation(
                schemaVersion: self::EXPLANATION_SCHEMA,
                module: $module,
                status: 'unknown',
                declaredConstraints: [],
                reasons: ['module_unknown'],
                hasTenantVerdict: false,
            );
        }

        $constraints = $this->declaredConstraints($compiledModule);
        $reasons = [];

        if (! $this->dependenciesSatisfied(module: $compiledModule, modules: $modules)) {
            $reasons[] = 'missing_dependency';
        }

        if ($context === null) {
            return new ModuleExplanation(
                schemaVersion: self::EXPLANATION_SCHEMA,
                module: $module,
                status: $reasons === [] ? 'declared' : 'inactive',
                declaredConstraints: $constraints,
                reasons: $reasons === [] ? ['static_declaration_only'] : $reasons,
                hasTenantVerdict: false,
            );
        }

        $active = $this->registry->isActive(name: $module, ctx: $context);

        if (! $active) {
            if ($reasons === []) {
                $reasons[] = 'not_enabled_for_tenant';
            }

            return new ModuleExplanation(
                schemaVersion: self::EXPLANATION_SCHEMA,
                module: $module,
                status: 'inactive',
                declaredConstraints: $constraints,
                reasons: $reasons,
                hasTenantVerdict: true,
            );
        }

        $edition = $this->resolveEdition($compiledModule);
        $gate = $this->activationGate->check(ctx: $context, module: $module, edition: $edition);

        if (! $gate->allowed) {
            $reasons[] = 'activation_gate_denied';

            return new ModuleExplanation(
                schemaVersion: self::EXPLANATION_SCHEMA,
                module: $module,
                status: 'inactive',
                declaredConstraints: $constraints,
                reasons: $reasons,
                hasTenantVerdict: true,
            );
        }

        return new ModuleExplanation(
            schemaVersion: self::EXPLANATION_SCHEMA,
            module: $module,
            status: 'active',
            declaredConstraints: $constraints,
            reasons: ['enabled_for_tenant'],
            hasTenantVerdict: true,
        );
    }

    public static function assertRevision(RegistryMap $map, string $expectedRevision): void
    {
        if ($map->revision !== $expectedRevision) {
            throw StaleRegistryRevision::mismatch(expected: $expectedRevision, actual: $map->revision);
        }
    }

    public static function assertExtensionPoint(string $name): void
    {
        if (! in_array($name, self::knownExtensionPointNames(), true)) {
            throw InvalidRegistryPoint::unknown($name);
        }
    }

    /** @return list<string> */
    public static function knownExtensionPointNames(): array
    {
        return array_values(self::EXTENDER_NAMES);
    }

    public static function revisionFor(CompiledRegistry $compiled): string
    {
        return $compiled->fingerprint;
    }

    private function compiledRegistry(): CompiledRegistry
    {
        $this->assertRegistryInputAvailable();

        return $this->registry->compiled();
    }

    /** @return array<string, CompiledModule> */
    private function moduleIndex(): array
    {
        $this->assertRegistryInputAvailable();

        $index = [];

        foreach ($this->registry->all() as $module) {
            $index[$module->composerName] = $module;
        }

        return $index;
    }

    private function assertRegistryInputAvailable(): void
    {
        if (! is_file($this->cachePath)) {
            if ($this->failIfMissing) {
                throw RegistryInputUnavailable::missing($this->cachePath);
            }

            if ($this->registry->all() === []) {
                throw RegistryInputUnavailable::missing($this->cachePath);
            }

            return;
        }

        /** @var mixed $payload */
        $payload = require $this->cachePath;

        if (! is_array($payload)) {
            throw RegistryInputUnavailable::corrupt();
        }
    }

    /**
     * @return list<array{kind: string, name?: string, entryCount?: int, owners?: array<string, int>, handle?: string, module?: string, capabilities?: list<string>, entityHandle?: string, sleeping?: bool}>
     */
    private function buildExtensionPoints(CompiledRegistry $compiled): array
    {
        $entityOwners = $this->ownersFromEntities($compiled->entities);
        $fieldOwners = $this->ownersFromEntityFields($compiled->entityFields);
        $apiOwners = $this->ownersFromApi($compiled->api);
        $permissionOwners = $this->ownersFromPermissions(permissions: $compiled->permissions, entities: $compiled->entities);
        $filamentOwners = $this->ownersFromFilament(filament: $compiled->filament, entities: $compiled->entities);
        $workflowOwners = $this->ownersFromWorkflow(workflow: $compiled->workflow, entities: $compiled->entities);

        $points = [];

        foreach (PipelineStages::ORDER as $extenderClass) {
            $name = self::EXTENDER_NAMES[$extenderClass];

            $owners = match ($name) {
                'entity' => $entityOwners,
                'entity_field' => $fieldOwners,
                'permissions' => $permissionOwners,
                'api' => $apiOwners,
                'filament' => $filamentOwners,
                'workflow' => $workflowOwners,
                default => $this->ownersByCount($this->sliceCount(compiled: $compiled, name: $name)),
            };

            $points[] = [
                'kind' => 'extender',
                'name' => $name,
                'entryCount' => array_sum($owners),
                'owners' => $owners,
            ];
        }

        foreach ($compiled->entities as $entity) {
            $points[] = [
                'kind' => 'entity',
                'handle' => $entity->handle,
                'module' => $entity->module,
                'capabilities' => array_map(static fn (Capability $capability): string => $capability->value, $entity->capabilities),
            ];
        }

        foreach ($compiled->entityFields as $field) {
            $points[] = [
                'kind' => 'entity_field',
                'entityHandle' => $field->extender->entityHandle,
                'module' => $field->declaringModule,
                'sleeping' => $field->sleeping,
            ];
        }

        return $points;
    }

    /**
     * @param  array<string, EntityDefinition>  $entities
     * @return array<string, int>
     */
    private function ownersFromEntities(array $entities): array
    {
        $owners = [];

        foreach ($entities as $entity) {
            $owners[$entity->module] = ($owners[$entity->module] ?? 0) + 1;
        }

        ksort($owners);

        return $owners;
    }

    /**
     * @param  list<CompiledEntityField>  $fields
     * @return array<string, int>
     */
    private function ownersFromEntityFields(array $fields): array
    {
        $owners = [];

        foreach ($fields as $field) {
            $owners[$field->declaringModule] = ($owners[$field->declaringModule] ?? 0) + 1;
        }

        ksort($owners);

        return $owners;
    }

    /**
     * @param  list<Api>  $api
     * @return array<string, int>
     */
    private function ownersFromApi(array $api): array
    {
        $owners = [];

        foreach ($api as $declaration) {
            $module = $declaration->declaringModule ?? 'unknown';
            $owners[$module] = ($owners[$module] ?? 0) + 1;
        }

        ksort($owners);

        return $owners;
    }

    /**
     * @param  list<Permissions>  $permissions
     * @param  array<string, EntityDefinition>  $entities
     * @return array<string, int>
     */
    private function ownersFromPermissions(array $permissions, array $entities): array
    {
        $owners = [];

        foreach ($permissions as $permission) {
            $module = $entities[$permission->entity]->module ?? 'unknown';
            $owners[$module] = ($owners[$module] ?? 0) + 1;
        }

        ksort($owners);

        return $owners;
    }

    /**
     * @param  list<Filament>  $filament
     * @param  array<string, EntityDefinition>  $entities
     * @return array<string, int>
     */
    private function ownersFromFilament(array $filament, array $entities): array
    {
        $owners = [];

        foreach ($filament as $entry) {
            $module = $entry->entityHandle !== null
                ? ($entities[$entry->entityHandle]->module ?? 'unknown')
                : 'unattributed';
            $owners[$module] = ($owners[$module] ?? 0) + 1;
        }

        ksort($owners);

        return $owners;
    }

    /**
     * @param  list<Workflow>  $workflow
     * @param  array<string, EntityDefinition>  $entities
     * @return array<string, int>
     */
    private function ownersFromWorkflow(array $workflow, array $entities): array
    {
        $owners = [];

        foreach ($workflow as $entry) {
            $module = $entry->entityHandle !== null
                ? ($entities[$entry->entityHandle]->module ?? 'unknown')
                : 'unattributed';
            $owners[$module] = ($owners[$module] ?? 0) + 1;
        }

        ksort($owners);

        return $owners;
    }

    /** @return array<string, int> */
    private function ownersByCount(int $count): array
    {
        return $count > 0 ? ['compiled' => $count] : [];
    }

    private function sliceCount(CompiledRegistry $compiled, string $name): int
    {
        return match ($name) {
            'entity' => count($compiled->entities),
            'entity_field' => count($compiled->entityFields),
            'permissions' => count($compiled->permissions),
            'api' => count($compiled->api),
            'filament' => count($compiled->filament),
            'menu' => count($compiled->menu),
            'routes' => count($compiled->routes),
            'workflow' => count($compiled->workflow),
            default => 0,
        };
    }

    private function assertModuleName(string $module): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9._-]*\/[a-z0-9][a-z0-9._-]*$/', $module) !== 1) {
            throw new ModuleNotFound($module);
        }
    }

    /** @return array{requires: list<array{kind: string, package: string, constraint: string}>, editions: array<string, list<string>>, components: list<class-string>, compatibilityPassport: string, installedPackagePresent: bool} */
    private function declaredConstraints(CompiledModule $module): array
    {
        return [
            'requires' => $module->requires,
            'editions' => $module->editions,
            'components' => $module->components,
            'compatibilityPassport' => 'pending',
            'installedPackagePresent' => in_array($module->composerName, InstalledPackages::discover($this->installedJsonPath), true),
        ];
    }

    /**
     * @param  array<string, CompiledModule>  $modules
     */
    private function dependenciesSatisfied(CompiledModule $module, array $modules): bool
    {
        $installed = InstalledPackages::discover($this->installedJsonPath);

        foreach ($module->requires as $requirement) {
            if ($requirement['kind'] !== 'module') {
                if (! in_array($requirement['package'], $installed, true)) {
                    return false;
                }

                continue;
            }

            if (! array_key_exists($requirement['package'], $modules)) {
                return false;
            }
        }

        return true;
    }

    private function resolveEdition(CompiledModule $module): string
    {
        $editions = array_keys($module->editions);

        return $editions[0] ?? 'default';
    }
}
