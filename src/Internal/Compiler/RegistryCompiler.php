<?php

declare(strict_types=1);

namespace Packman\Modules\Internal\Compiler;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Packman\Data\Laravel\JsonDocuments;
use Packman\Features\Data\FeatureFlagDefinition;
use Packman\Modules\Contracts\Compiler\FieldCastRegistry;
use Packman\Modules\Contracts\Compiler\ManifestSectionCompiler;
use Packman\Modules\Contracts\Compiler\ModuleCompilation;
use Packman\Modules\Data\Compiler\CompiledEntityField;
use Packman\Modules\Data\Compiler\CompiledRegistry;
use Packman\Modules\Data\Compiler\CompiledSettingDefault;
use Packman\Modules\Data\Compiler\InstalledPackageVersion;
use Packman\Modules\Data\Compiler\NormalizedExtension;
use Packman\Modules\Data\Entities\Entity;
use Packman\Modules\Data\Entities\EntityDefinition;
use Packman\Modules\Data\Entities\EntityField;
use Packman\Modules\Data\Entities\FieldDefinition;
use Packman\Modules\Data\Extenders\Api;
use Packman\Modules\Data\Extenders\Filament;
use Packman\Modules\Data\Extenders\Menu;
use Packman\Modules\Data\Extenders\Permissions;
use Packman\Modules\Data\Extenders\Routes;
use Packman\Modules\Data\Extenders\Workflow;
use Packman\Modules\Data\Lifecycle\ModuleManifest;
use Packman\Modules\Enums\Entities\Capability;
use Packman\Modules\Exceptions\Compiler\CompilationException;

/**
 * Applies the closed 8-extender set to a set of manifests in a fixed,
 * deterministic pipeline: Entity → EntityField → Permissions → Api →
 * Filament → Menu → Routes → Workflow. Output is an in-memory
 * CompiledRegistry — atomic file write and hot-path reads belong to
 * ModuleRegistry, not here.
 *
 * @internal spec: B-10 §4.2/§5.2, P1.5
 */
final readonly class RegistryCompiler implements ModuleCompilation
{
    /** @var list<ManifestSectionCompiler> */
    private array $sections;

    /** @param iterable<ManifestSectionCompiler> $sections */
    public function __construct(
        private CompilationValidator $validator = new CompilationValidator,
        private FieldCastRegistry $casts = new FieldCastMap,
        iterable $sections = [],
        private ?string $installedJsonPath = null,
        private string $compilerPackage = 'axiomasoft/packman-modules',
    ) {
        $collected = [];
        foreach ($sections as $section) {
            $collected[] = $section;
        }
        $this->sections = $collected;
    }

    /**
     * @param  list<ModuleManifest>  $manifests
     * @param  list<string>|null  $installedPackages  composer names known installed beyond
     *                                                $manifests. Defaults to a real read of
     *                                                `vendor/composer/installed.json` ({@see
     *                                                InstalledPackages}) — pass an explicit list in
     *                                                tests/testbench to avoid depending on the host's
     *                                                installed.json contents.
     * @param  (callable(class-string): void)|null  $onStage  observes each {@see PipelineStages}
     *                                                        entry as its stage starts, in the order
     *                                                        actually executed — test seam for
     *                                                        pipeline determinism.
     * @param  array<string, InstalledPackageVersion>|null  $installedVersions  Explicit
     *                                                                          metadata for contract fixtures.
     *
     * @internal spec: AC-2, AC-18
     */
    public function compile(array $manifests, ?array $installedPackages = null, ?callable $onStage = null, ?array $installedVersions = null): CompiledRegistry
    {
        $this->validator->validateRequiredFields($manifests);
        $this->validator->validateExtenderSet($manifests);
        $this->validator->validateTablePrefixes($manifests);
        $this->validator->validateRequires(manifests: $manifests, installedPackages: $installedPackages ?? InstalledPackages::discover($this->installedJsonPath));
        $this->validator->validateComponents($manifests);
        $this->validator->validateDependencyGraph($manifests);

        usort($manifests, static fn (ModuleManifest $left, ModuleManifest $right): int => $left->composerName <=> $right->composerName);

        $versions = $installedVersions ?? InstalledPackages::discoverVersions($this->installedJsonPath);
        $normalized = $this->normalize(manifests: $manifests, versions: $versions);
        $this->validator->validateNormalizedIdentities($normalized);
        $applied = $this->validator->applyReplacements(records: $normalized, manifests: $manifests);
        $this->validator->validateMenuOrder($applied['effective']);
        $effective = $this->order($applied['effective']);
        $removed = $this->removedKeys(all: $normalized, effective: $effective);

        $entities = [];
        $entityFields = [];
        $permissions = [];
        $api = [];
        $filament = [];
        $menu = [];
        $routes = [];
        $workflow = [];

        foreach (PipelineStages::ORDER as $stage) {
            if ($onStage !== null) {
                $onStage($stage);
            }

            match ($stage) {
                Entity::class => $entities = $this->applyEntityExtenders($manifests),
                EntityField::class => $entityFields = $this->applyEntityFieldExtenders(manifests: $manifests, entities: $entities),
                Permissions::class => $permissions = $this->collectPermissions($manifests),
                Api::class => $api = $this->collectApi($manifests),
                Filament::class => $filament = $this->collectFilament($manifests),
                Menu::class => $menu = $this->collectMenu($manifests),
                Routes::class => $routes = $this->collectRoutes($manifests),
                Workflow::class => $workflow = $this->collectWorkflow($manifests),
            };
        }

        $compiler = $this->compilerIdentity($versions);
        $modules = array_map(
            fn (ModuleManifest $manifest): array => $this->packageVersion(versions: $versions, name: $manifest->composerName)->canonical(),
            $manifests,
        );
        $extensions = array_map(static fn (NormalizedExtension $record): array => $record->canonical(), $effective);
        $fingerprint = CompiledRegistry::hashCanonical(
            compiler: $compiler,
            modules: $modules,
            extensions: $extensions,
            replacements: $applied['replacements'],
        );

        return new CompiledRegistry(
            entities: $this->filterEntities(entities: $entities, removed: $removed),
            entityFields: $this->detachEntityFields($this->filterEntityFields(fields: $entityFields, removed: $removed)),
            permissions: $this->detachExtenders(class: Permissions::class, items: $this->filterPermissions(permissions: $permissions, removed: $removed)),
            api: $this->detachExtenders(class: Api::class, items: $this->filterApi(api: $api, removed: $removed)),
            filament: $this->detachExtenders(class: Filament::class, items: $this->filterFilament(filament: $filament, manifests: $manifests, removed: $removed)),
            menu: $this->detachExtenders(class: Menu::class, items: $this->orderedMenus(menu: $this->filterMenus(menu: $menu, removed: $removed), effective: $effective)),
            routes: $this->detachExtenders(class: Routes::class, items: $this->filterRoutes(routes: $routes, manifests: $manifests, removed: $removed)),
            workflow: $this->detachExtenders(class: Workflow::class, items: $this->filterWorkflow(workflow: $workflow, manifests: $manifests, removed: $removed)),
            settings: $this->filterSettings(settings: $this->collectSettings($manifests), removed: $removed),
            featureFlags: $this->filterFeatureFlags(flags: $this->collectFeatureFlags($manifests), removed: $removed),
            listeners: $this->filterListeners(listeners: array_column(array_map(static fn (ModuleManifest $manifest): array => [
                'module' => $manifest->composerName, 'listeners' => $manifest->listeners,
            ], $manifests), 'listeners', 'module'), removed: $removed),
            fingerprint: $fingerprint,
            compiler: $compiler,
            modules: $modules,
            extensions: $extensions,
            replacements: $applied['replacements'],
        );
    }

    /**
     * `ModuleManifest::$settings`/`$featureFlags` are plain manifest properties,
     * not entries in the closed 8-extender pipeline — they never reached
     * the compiled file before this — collected here directly, outside
     * {@see PipelineStages::ORDER}.
     *
     * @param  list<ModuleManifest>  $manifests
     * @return list<CompiledSettingDefault>
     *
     * @internal spec: P1.25, A68
     */
    private function collectSettings(array $manifests): array
    {
        $settings = [];

        foreach ($manifests as $manifest) {
            foreach ($manifest->settings as $default) {
                $settings[] = new CompiledSettingDefault(namespace: $this->settingsNamespaceFor($manifest), definition: $default);
            }
        }

        return $settings;
    }

    private function settingsNamespaceFor(ModuleManifest $manifest): string
    {
        return $manifest->settingsNamespace ?? $manifest->tablePrefix
            ?? throw CompilationException::tablePrefixInvariantBypassed($manifest->composerName);
    }

    /**
     * @param  list<ModuleManifest>  $manifests
     * @return list<FeatureFlagDefinition>
     */
    private function collectFeatureFlags(array $manifests): array
    {
        $flags = [];

        foreach ($manifests as $manifest) {
            foreach ($manifest->featureFlags as $flag) {
                $flags[] = $flag;
            }
        }

        return $flags;
    }

    /**
     * @param  list<ModuleManifest>  $manifests
     * @return array<string, EntityDefinition>
     */
    private function applyEntityExtenders(array $manifests): array
    {
        $entities = [];

        foreach ($manifests as $manifest) {
            foreach ($manifest->extenders as $extender) {
                if (! $extender instanceof Entity) {
                    continue;
                }

                $definition = $this->compileEntity(manifest: $manifest, extender: $extender);

                if (isset($entities[$definition->handle])) {
                    throw CompilationException::duplicateEntityHandle(
                        handle: $definition->handle,
                        firstModule: $entities[$definition->handle]->module,
                        secondModule: $manifest->composerName,
                    );
                }

                $entities[$definition->handle] = $definition;
            }
        }

        return $entities;
    }

    private function compileEntity(ModuleManifest $manifest, Entity $extender): EntityDefinition
    {
        $modulePrefix = $manifest->tablePrefix;

        if ($modulePrefix === null) {
            // Guarded by CompilationValidator::validateRequiredFields() before the pipeline
            // starts (entity owners declare their prefix, no auto-derivation from the composer
            // name); this branch only matters if compileEntity() is ever reached directly.
            // Distinct throw: a validation bypass, not an ordinary rejection.
            throw CompilationException::tablePrefixInvariantBypassed($manifest->composerName);
        }

        $handle = $extender->handle ?? sprintf('%s.%s', $modulePrefix, Str::snake(class_basename($extender->model)));
        $table = $extender->table ?? $modulePrefix.'_'.Str::snake(Str::plural(class_basename($extender->model)));

        if (! str_starts_with($table, $modulePrefix.'_')) {
            throw CompilationException::tablePrefixMismatch(composerName: $manifest->composerName, table: $table, expectedPrefix: $modulePrefix);
        }

        $labelSingular = $extender->labelSingular ?? Str::headline(class_basename($extender->model));

        return new EntityDefinition(
            handle: $handle,
            model: $extender->model,
            table: $table,
            module: $manifest->composerName,
            labelSingular: $labelSingular,
            labelPlural: $extender->labelPlural ?? Str::plural($labelSingular),
            capabilities: $this->capabilitiesFor($extender),
            fields: $extender->fieldDefinitions ?? $this->introspectCasts($extender->model),
            titleAttribute: $extender->titleAttribute,
        );
    }

    /** @return list<Capability> */
    private function capabilitiesFor(Entity $extender): array
    {
        $capabilities = [];

        if ($extender->isSearchable) {
            $capabilities[] = Capability::Searchable;
        }

        if ($extender->isAuditable) {
            $capabilities[] = Capability::Auditable;
        }

        if ($extender->hasCustomFields) {
            $capabilities[] = Capability::CustomFields;
        }

        if ($extender->isWorkflowable) {
            $capabilities[] = Capability::Workflowable;
        }

        if ($extender->isCommentable) {
            $capabilities[] = Capability::Commentable;
        }

        if ($extender->isDocumentable) {
            $capabilities[] = Capability::Documentable;
        }

        return $capabilities;
    }

    /**
     * Falls back to introspecting the model's `$casts` when the extender
     * doesn't declare fields explicitly.
     *
     * @param  class-string  $model
     * @return list<FieldDefinition>
     *
     * @internal spec: B-10 §4.2
     */
    private function introspectCasts(string $model): array
    {
        $instance = new $model;
        assert($instance instanceof Model);

        $fields = [];

        foreach ($instance->getCasts() as $attribute => $cast) {
            $fields[] = new FieldDefinition(handle: $attribute, type: $this->mapCastType($cast));
        }

        return $fields;
    }

    /** @return 'string'|'int'|'decimal'|'bool'|'date'|'datetime'|'json'|'money'|'relation'|'enum' */
    private function mapCastType(string $cast): string
    {
        $registered = $this->casts->typeFor($cast);

        if ($registered !== null) {
            return $registered;
        }

        return match (true) {
            in_array($cast, ['int', 'integer'], true) => 'int',
            str_starts_with($cast, 'decimal:') => 'decimal',
            in_array($cast, ['bool', 'boolean'], true) => 'bool',
            $cast === 'date' => 'date',
            str_starts_with($cast, 'datetime') || str_starts_with($cast, 'immutable_datetime') => 'datetime',
            in_array($cast, ['array', 'json', 'collection', 'object'], true) => 'json',
            default => 'string',
        };
    }

    /**
     * @param  list<ModuleManifest>  $manifests
     * @param  array<string, EntityDefinition>  $entities
     * @return list<CompiledEntityField>
     */
    private function applyEntityFieldExtenders(array $manifests, array $entities): array
    {
        $compiled = [];

        foreach ($manifests as $manifest) {
            foreach ($manifest->extenders as $extender) {
                if (! $extender instanceof EntityField) {
                    continue;
                }

                $target = $entities[$extender->entityHandle] ?? null;
                $this->validator->validateEntityHandleKnown(declaringComposerName: $manifest->composerName, entityHandle: $extender->entityHandle, known: $target !== null);

                /** @var EntityDefinition $target */
                $compiled[] = new CompiledEntityField(
                    extender: $extender,
                    declaringModule: $manifest->composerName,
                    sleeping: $target->module !== $manifest->composerName,
                );
            }
        }

        return $compiled;
    }

    /**
     * @template T of \Packman\Modules\Contracts\Compiler\Extender
     *
     * @param  list<ModuleManifest>  $manifests
     * @param  class-string<T>  $extenderClass
     * @return list<T>
     */
    private function collect(array $manifests, string $extenderClass): array
    {
        $result = [];

        foreach ($manifests as $manifest) {
            foreach ($manifest->extenders as $extender) {
                if ($extender instanceof $extenderClass) {
                    $result[] = $extender;
                }
            }
        }

        return $result;
    }

    /**
     * Concretely-typed wrappers around {@see collect()} (one per remaining
     * extender kind, PipelineStages::ORDER slots 3–8). Template inference on
     * a direct `collect($manifests, X::class)` call loses T when the call
     * site sits inside a `match` arm; a named method with its own concrete
     * `@return` gives the match arm a type PHPStan doesn't have to re-derive.
     *
     * @param  list<ModuleManifest>  $manifests
     * @return list<Permissions>
     */
    private function collectPermissions(array $manifests): array
    {
        return $this->collect(manifests: $manifests, extenderClass: Permissions::class);
    }

    /**
     * @param  list<ModuleManifest>  $manifests
     * @return list<Api>
     */
    private function collectApi(array $manifests): array
    {
        $result = [];

        foreach ($manifests as $manifest) {
            foreach ($manifest->extenders as $extender) {
                if (! $extender instanceof Api) {
                    continue;
                }

                $api = clone $extender;
                $api->declaringModule = $manifest->composerName;
                $result[] = $api;
            }
        }

        foreach ($result as $api) {
            if (! in_array($api->mode, ['entity', 'extendResource'], true)) {
                $this->sectionFor($api->mode);
            }
        }

        $sections = $this->sections;
        usort($sections, static fn (ManifestSectionCompiler $a, ManifestSectionCompiler $b): int => $a->phase() <=> $b->phase());

        foreach ($sections as $section) {
            $section->compile(array_values(array_filter($result, static fn (Api $api): bool => $api->mode === $section->mode())), $result);
        }

        return $result;
    }

    private function sectionFor(string $mode): ManifestSectionCompiler
    {
        foreach ($this->sections as $section) {
            if ($section->mode() === $mode) {
                return $section;
            }
        }

        throw new CompilationException('No compiler for API declaration mode: '.$mode);
    }

    /**
     * @param  list<ModuleManifest>  $manifests
     * @return list<Filament>
     */
    private function collectFilament(array $manifests): array
    {
        return $this->collect(manifests: $manifests, extenderClass: Filament::class);
    }

    /**
     * @param  list<ModuleManifest>  $manifests
     * @return list<Menu>
     */
    private function collectMenu(array $manifests): array
    {
        return $this->collect(manifests: $manifests, extenderClass: Menu::class);
    }

    /**
     * @param  list<ModuleManifest>  $manifests
     * @return list<Routes>
     */
    private function collectRoutes(array $manifests): array
    {
        return $this->collect(manifests: $manifests, extenderClass: Routes::class);
    }

    /**
     * @param  list<ModuleManifest>  $manifests
     * @return list<Workflow>
     */
    private function collectWorkflow(array $manifests): array
    {
        return $this->collect(manifests: $manifests, extenderClass: Workflow::class);
    }

    /**
     * @param  list<ModuleManifest>  $manifests
     * @param  array<string, InstalledPackageVersion>  $versions
     * @return list<NormalizedExtension>
     */
    private function normalize(array $manifests, array $versions): array
    {
        $records = [];

        foreach ($manifests as $manifest) {
            $ownerVersion = $this->packageVersion(versions: $versions, name: $manifest->composerName)->version;

            foreach ($manifest->extenders as $extender) {
                array_push($records, ...$this->normalizeExtender(manifest: $manifest, extender: $extender, ownerVersion: $ownerVersion));
            }

            foreach ($manifest->settings as $setting) {
                $records[] = new NormalizedExtension(
                    point: 'settings',
                    id: $this->settingsNamespaceFor($manifest).':'.$setting->key,
                    owner: $manifest->composerName,
                    ownerVersion: $ownerVersion,
                    payload: [
                        'key' => $setting->key,
                        'default' => $setting->default,
                        'workspaceScoped' => $setting->workspaceScoped,
                        'userScoped' => $setting->userScoped,
                        'sensitive' => $setting->sensitive,
                    ],
                );
            }

            foreach ($manifest->featureFlags as $flag) {
                $records[] = new NormalizedExtension(
                    point: 'feature_flags',
                    id: $flag->key,
                    owner: $manifest->composerName,
                    ownerVersion: $ownerVersion,
                    payload: [
                        'default' => $flag->default,
                        'module' => $flag->module,
                        'payload' => JsonDocuments::optionalArray($flag->payload),
                    ],
                );
            }

            foreach ($manifest->listeners as $event => $listeners) {
                foreach ($listeners as $listener) {
                    $records[] = new NormalizedExtension(
                        point: 'listeners',
                        id: $manifest->composerName.':'.$event.':'.$listener,
                        owner: $manifest->composerName,
                        ownerVersion: $ownerVersion,
                        payload: ['event' => $event, 'listener' => $listener],
                    );
                }
            }
        }

        return $records;
    }

    /**
     * @return list<NormalizedExtension>
     */
    private function normalizeExtender(ModuleManifest $manifest, mixed $extender, string $ownerVersion): array
    {
        if ($extender instanceof Entity) {
            $handle = $this->resolvedEntityHandle(manifest: $manifest, extender: $extender);
            $fields = $extender->fieldDefinitions ?? $this->introspectCasts($extender->model);
            $records = [
                new NormalizedExtension(
                    point: 'entity',
                    id: $handle,
                    owner: $manifest->composerName,
                    ownerVersion: $ownerVersion,
                    payload: ['model' => $extender->model, 'table' => $extender->table],
                ),
            ];

            foreach ($fields as $field) {
                $records[] = new NormalizedExtension(
                    point: 'entity_field',
                    id: $handle.'.'.$field->handle,
                    owner: $manifest->composerName,
                    ownerVersion: $ownerVersion,
                    payload: $field->toArray(),
                );
            }

            return $records;
        }

        if ($extender instanceof EntityField) {
            return [
                new NormalizedExtension(
                    point: 'entity_field',
                    id: $extender->entityHandle.'.'.$extender->fieldHandle,
                    owner: $manifest->composerName,
                    ownerVersion: $ownerVersion,
                    payload: CompiledRegistry::extenderToArray($extender),
                ),
            ];
        }

        if ($extender instanceof Permissions) {
            return [
                new NormalizedExtension(
                    point: 'permissions',
                    id: $extender->panel.':'.$extender->entity,
                    owner: $manifest->composerName,
                    ownerVersion: $ownerVersion,
                    payload: ['actions' => $extender->actions, 'scopeable' => $extender->isScopeable],
                ),
            ];
        }

        if ($extender instanceof Api) {
            return $this->normalizeApi(manifest: $manifest, api: $extender, ownerVersion: $ownerVersion);
        }

        if ($extender instanceof Filament) {
            $records = [];

            foreach ($extender->classes as $class) {
                $subject = $extender->kind === 'tab' ? ($extender->entityHandle ?? $class) : $class;
                $records[] = new NormalizedExtension(
                    point: 'filament',
                    id: $manifest->composerName.':'.$extender->panel.':'.$extender->kind.':'.$subject,
                    owner: $manifest->composerName,
                    ownerVersion: $ownerVersion,
                    payload: ['class' => $class, 'entityHandle' => $extender->entityHandle],
                );
            }

            return $records;
        }

        if ($extender instanceof Menu) {
            return [
                new NormalizedExtension(
                    point: 'menu',
                    id: $extender->panel.':'.$extender->handle,
                    owner: $manifest->composerName,
                    ownerVersion: $ownerVersion,
                    payload: ['label' => $extender->label, 'route' => $extender->routeName],
                    after: $extender->afterHandle,
                ),
            ];
        }

        if ($extender instanceof Routes) {
            $file = basename(str_replace('\\', '/', $extender->file));

            return [
                new NormalizedExtension(
                    point: 'routes',
                    id: $manifest->composerName.':'.$extender->kind.':'.$file.':'.($extender->routePrefix ?? ''),
                    owner: $manifest->composerName,
                    ownerVersion: $ownerVersion,
                    payload: ['file' => $file, 'middlewares' => $extender->middlewares],
                ),
            ];
        }

        if ($extender instanceof Workflow) {
            if ($extender->kind === 'stepType') {
                return [
                    new NormalizedExtension(
                        point: 'workflow.step',
                        id: (string) $extender->stepClass,
                        owner: $manifest->composerName,
                        ownerVersion: $ownerVersion,
                        payload: [],
                    ),
                ];
            }

            $records = [];

            foreach ($extender->eventNames as $event) {
                $records[] = new NormalizedExtension(
                    point: 'workflow.trigger',
                    id: $manifest->composerName.':'.($extender->entityHandle ?? '').':'.$event,
                    owner: $manifest->composerName,
                    ownerVersion: $ownerVersion,
                    payload: ['event' => $event],
                );
            }

            return $records;
        }

        return [];
    }

    /**
     * @return list<NormalizedExtension>
     */
    private function normalizeApi(ModuleManifest $manifest, Api $api, string $ownerVersion): array
    {
        if (! in_array($api->mode, ['entity', 'extendResource'], true)) {
            return $this->sectionFor($api->mode)->normalize(manifest: $manifest, api: $api, ownerVersion: $ownerVersion);
        }

        return [
            new NormalizedExtension(
                point: 'api.resource',
                id: $manifest->composerName.':'.$api->mode.':'.$api->entityHandle,
                owner: $manifest->composerName,
                ownerVersion: $ownerVersion,
                payload: ['mode' => $api->mode],
            ),
        ];
    }

    /**
     * @param  list<NormalizedExtension>  $records
     * @return list<NormalizedExtension>
     */
    private function order(array $records): array
    {
        $grouped = [];

        foreach ($records as $record) {
            $grouped[$record->point][] = $record;
        }

        ksort($grouped);

        $ordered = [];

        foreach ($grouped as $point => $items) {
            $ordered = [...$ordered, ...($point === 'menu' ? $this->orderMenus($items) : $this->orderDefault($items))];
        }

        return $ordered;
    }

    /**
     * @param  list<NormalizedExtension>  $items
     * @return list<NormalizedExtension>
     */
    private function orderDefault(array $items): array
    {
        usort($items, function (NormalizedExtension $left, NormalizedExtension $right): int {
            $leftPriority = is_numeric($left->payload['priority'] ?? null) ? (int) $left->payload['priority'] : null;
            $rightPriority = is_numeric($right->payload['priority'] ?? null) ? (int) $right->payload['priority'] : null;

            if ($leftPriority !== null || $rightPriority !== null) {
                $priority = ($rightPriority ?? 0) <=> ($leftPriority ?? 0);

                return $priority !== 0 ? $priority : $left->id <=> $right->id;
            }

            return $left->id <=> $right->id;
        });

        return $items;
    }

    /**
     * @param  list<NormalizedExtension>  $items
     * @return list<NormalizedExtension>
     */
    private function orderMenus(array $items): array
    {
        /** @var array<string, NormalizedExtension> $byId */
        $byId = [];
        $incoming = [];

        foreach ($items as $item) {
            $byId[$item->id] = $item;
            $incoming[$item->id] = 0;
        }

        foreach ($items as $item) {
            if ($item->after === null) {
                continue;
            }

            $incoming[$item->id]++;
        }

        $ordered = [];

        while ($byId !== []) {
            $frontier = array_keys(array_filter($incoming, static fn (int $count, string $id): bool => $count === 0 && isset($byId[$id]), ARRAY_FILTER_USE_BOTH));
            sort($frontier);

            if ($frontier === []) {
                throw CompilationException::menuCycle(implode(', ', array_keys($byId)));
            }

            $id = $frontier[0];
            $ordered[] = $byId[$id];
            unset($byId[$id], $incoming[$id]);

            foreach ($items as $item) {
                if ($item->after === null) {
                    continue;
                }

                $afterId = explode(':', $item->id, 2)[0].':'.$item->after;

                if ($afterId === $id && isset($incoming[$item->id])) {
                    $incoming[$item->id]--;
                }
            }
        }

        return $ordered;
    }

    /**
     * @param  list<NormalizedExtension>  $all
     * @param  list<NormalizedExtension>  $effective
     * @return array<string, true>
     */
    private function removedKeys(array $all, array $effective): array
    {
        $kept = [];

        foreach ($effective as $record) {
            $kept[$record->key()] = true;
        }

        $removed = [];

        foreach ($all as $record) {
            if (! isset($kept[$record->key()])) {
                $removed[$record->key()] = true;
            }
        }

        return $removed;
    }

    /**
     * @param  array<string, EntityDefinition>  $entities
     * @param  array<string, true>  $removed
     * @return array<string, EntityDefinition>
     */
    private function filterEntities(array $entities, array $removed): array
    {
        $filtered = array_filter(
            $entities,
            static fn (EntityDefinition $entity): bool => ! isset($removed['entity'."\0".$entity->handle]),
        );
        ksort($filtered);

        return $filtered;
    }

    /**
     * @param  list<CompiledEntityField>  $fields
     * @param  array<string, true>  $removed
     * @return list<CompiledEntityField>
     */
    private function filterEntityFields(array $fields, array $removed): array
    {
        return array_values(array_filter(
            $fields,
            static fn (CompiledEntityField $field): bool => ! isset($removed['entity_field'."\0".$field->extender->entityHandle.'.'.$field->extender->fieldHandle]),
        ));
    }

    /**
     * @param  list<Permissions>  $permissions
     * @param  array<string, true>  $removed
     * @return list<Permissions>
     */
    private function filterPermissions(array $permissions, array $removed): array
    {
        return array_values(array_filter(
            $permissions,
            static fn (Permissions $item): bool => ! isset($removed['permissions'."\0".$item->panel.':'.$item->entity]),
        ));
    }

    /**
     * @param  list<Api>  $api
     * @param  array<string, true>  $removed
     * @return list<Api>
     */
    private function filterApi(array $api, array $removed): array
    {
        return array_values(array_filter($api, function (Api $item) use ($removed): bool {
            $id = match ($item->mode) {
                'operation' => 'api.operation'."\0".$item->version.':'.$item->operationId,
                'tool' => 'api.tool'."\0".$item->declaringModule.':'.$item->toolCode.':'.$item->toolVersion,
                default => 'api.resource'."\0".$item->declaringModule.':'.$item->mode.':'.$item->entityHandle,
            };

            return ! isset($removed[$id]);
        }));
    }

    /**
     * @param  list<Filament>  $filament
     * @param  list<ModuleManifest>  $manifests
     * @param  array<string, true>  $removed
     * @return list<Filament>
     */
    private function filterFilament(array $filament, array $manifests, array $removed): array
    {
        $owners = $this->extenderOwners(manifests: $manifests, class: Filament::class);

        return array_values(array_filter($filament, function (Filament $item) use ($removed, $owners): bool {
            $owner = $owners[spl_object_id($item)] ?? '';

            foreach ($item->classes as $class) {
                $subject = $item->kind === 'tab' ? ($item->entityHandle ?? $class) : $class;

                if (! isset($removed['filament'."\0".$owner.':'.$item->panel.':'.$item->kind.':'.$subject])) {
                    return true;
                }
            }

            return $item->classes === [];
        }));
    }

    /**
     * @param  list<Menu>  $menu
     * @param  array<string, true>  $removed
     * @return list<Menu>
     */
    private function filterMenus(array $menu, array $removed): array
    {
        return array_values(array_filter(
            $menu,
            static fn (Menu $item): bool => ! isset($removed['menu'."\0".$item->panel.':'.$item->handle]),
        ));
    }

    /**
     * @param  list<Menu>  $menu
     * @param  list<NormalizedExtension>  $effective
     * @return list<Menu>
     */
    private function orderedMenus(array $menu, array $effective): array
    {
        $order = [];

        foreach ($effective as $record) {
            if ($record->point === 'menu') {
                $order[] = $record->id;
            }
        }

        usort($menu, function (Menu $left, Menu $right) use ($order): int {
            $leftId = $left->panel.':'.$left->handle;
            $rightId = $right->panel.':'.$right->handle;

            return (! in_array($leftId, $order, true) ? 0 : array_search($leftId, $order, true)) <=> (! in_array($rightId, $order, true) ? 0 : array_search($rightId, $order, true));
        });

        return $menu;
    }

    /**
     * @param  list<Routes>  $routes
     * @param  list<ModuleManifest>  $manifests
     * @param  array<string, true>  $removed
     * @return list<Routes>
     */
    private function filterRoutes(array $routes, array $manifests, array $removed): array
    {
        $owners = $this->extenderOwners(manifests: $manifests, class: Routes::class);

        return array_values(array_filter($routes, function (Routes $item) use ($removed, $owners): bool {
            $owner = $owners[spl_object_id($item)] ?? '';
            $file = basename(str_replace('\\', '/', $item->file));

            return ! isset($removed['routes'."\0".$owner.':'.$item->kind.':'.$file.':'.($item->routePrefix ?? '')]);
        }));
    }

    /**
     * @param  list<Workflow>  $workflow
     * @param  list<ModuleManifest>  $manifests
     * @param  array<string, true>  $removed
     * @return list<Workflow>
     */
    private function filterWorkflow(array $workflow, array $manifests, array $removed): array
    {
        $owners = $this->extenderOwners(manifests: $manifests, class: Workflow::class);

        return array_values(array_filter($workflow, function (Workflow $item) use ($removed, $owners): bool {
            $owner = $owners[spl_object_id($item)] ?? '';

            if ($item->kind === 'stepType') {
                return ! isset($removed['workflow.step'."\0".$item->stepClass]);
            }

            foreach ($item->eventNames as $event) {
                if (! isset($removed['workflow.trigger'."\0".$owner.':'.$item->entityHandle.':'.$event])) {
                    return true;
                }
            }

            return $item->eventNames === [];
        }));
    }

    /**
     * @param  list<CompiledSettingDefault>  $settings
     * @param  array<string, true>  $removed
     * @return list<CompiledSettingDefault>
     */
    private function filterSettings(array $settings, array $removed): array
    {
        return array_values(array_filter(
            $settings,
            static fn (CompiledSettingDefault $setting): bool => ! isset($removed['settings'."\0".$setting->namespace.':'.$setting->definition->key]),
        ));
    }

    /**
     * @param  list<FeatureFlagDefinition>  $flags
     * @param  array<string, true>  $removed
     * @return list<FeatureFlagDefinition>
     */
    private function filterFeatureFlags(array $flags, array $removed): array
    {
        return array_values(array_filter(
            $flags,
            static fn (FeatureFlagDefinition $flag): bool => ! isset($removed['feature_flags'."\0".$flag->key]),
        ));
    }

    /**
     * @param  array<string, array<class-string, list<class-string>>>  $listeners
     * @param  array<string, true>  $removed
     * @return array<string, array<class-string, list<class-string>>>
     */
    private function filterListeners(array $listeners, array $removed): array
    {
        $filtered = [];

        foreach ($listeners as $module => $events) {
            foreach ($events as $event => $handlers) {
                $kept = array_values(array_filter(
                    $handlers,
                    static fn (string $handler): bool => ! isset($removed['listeners'."\0".$module.':'.$event.':'.$handler]),
                ));

                if ($kept !== []) {
                    $filtered[$module][$event] = $kept;
                }
            }
        }

        ksort($filtered);

        return $filtered;
    }

    /**
     * @param  list<CompiledEntityField>  $fields
     * @return list<CompiledEntityField>
     */
    private function detachEntityFields(array $fields): array
    {
        return array_map(
            static fn (CompiledEntityField $field): CompiledEntityField => new CompiledEntityField(
                extender: CompiledRegistry::extenderFromArray(class: EntityField::class, data: CompiledRegistry::extenderToArray($field->extender)),
                declaringModule: $field->declaringModule,
                sleeping: $field->sleeping,
            ),
            $fields,
        );
    }

    /**
     * @template T of \Packman\Modules\Contracts\Compiler\Extender
     *
     * @param  class-string<T>  $class
     * @param  list<T>  $items
     * @return list<T>
     */
    private function detachExtenders(string $class, array $items): array
    {
        return array_map(
            static fn (mixed $item): mixed => CompiledRegistry::extenderFromArray(class: $class, data: CompiledRegistry::extenderToArray($item)),
            $items,
        );
    }

    /**
     * @template T of \Packman\Modules\Contracts\Compiler\Extender
     *
     * @param  list<ModuleManifest>  $manifests
     * @param  class-string<T>  $class
     * @return array<int, string>
     */
    private function extenderOwners(array $manifests, string $class): array
    {
        $owners = [];

        foreach ($manifests as $manifest) {
            foreach ($manifest->extenders as $extender) {
                if ($extender instanceof $class) {
                    $owners[spl_object_id($extender)] = $manifest->composerName;
                }
            }
        }

        return $owners;
    }

    /**
     * @param  array<string, InstalledPackageVersion>  $versions
     */
    private function packageVersion(array $versions, string $name): InstalledPackageVersion
    {
        return $versions[$name] ?? new InstalledPackageVersion(name: $name, version: '0.0.0', reference: null);
    }

    /**
     * @param  array<string, InstalledPackageVersion>  $versions
     * @return array{package: string, version: string, reference: ?string}
     */
    private function compilerIdentity(array $versions): array
    {
        $compiler = $this->packageVersion(versions: $versions, name: $this->compilerPackage);

        return [
            'package' => $compiler->name,
            'version' => $compiler->version,
            'reference' => $compiler->reference,
        ];
    }

    private function resolvedEntityHandle(ModuleManifest $manifest, Entity $extender): string
    {
        $prefix = $manifest->tablePrefix ?? throw CompilationException::tablePrefixInvariantBypassed($manifest->composerName);

        return $extender->handle ?? sprintf('%s.%s', $prefix, Str::snake(class_basename($extender->model)));
    }
}
