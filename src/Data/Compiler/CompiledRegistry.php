<?php

declare(strict_types=1);

namespace Packman\Modules\Data\Compiler;

use Closure;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Data\Laravel\JsonDocuments;
use Packman\Features\Data\FeatureFlagDefinition;
use Packman\Modules\Contracts\Compiler\Extender;
use Packman\Modules\Data\Entities\EntityDefinition;
use Packman\Modules\Data\Extenders\Api;
use Packman\Modules\Data\Extenders\Permissions;
use Packman\Modules\Exceptions\Compiler\CompilationException;
use Packman\Modules\Internal\Compiler\CompiledSettingDefault;
use Packman\Modules\Internal\Extend\Filament;
use Packman\Modules\Internal\Extend\Menu;
use Packman\Modules\Internal\Extend\Routes;
use Packman\Modules\Internal\Extend\Workflow;
use ReflectionClass;

/**
 * @phpstan-type RegistryPayload array{entities?: array<string, array{handle: string, model: class-string, table: string, module: string, labelSingular: string, labelPlural: string, capabilities: list<string>, fields: list<array{handle: string, type: 'string'|'int'|'decimal'|'bool'|'date'|'datetime'|'json'|'money'|'relation'|'enum', required?: bool, searchable?: bool, meta?: array<string, mixed>|null}>, titleAttribute: string|null}>, entityFields?: list<array{extender: array<string, mixed>, declaringModule: string, sleeping: bool}>, permissions?: list<array<string, mixed>>, api?: list<array<string, mixed>>, filament?: list<array<string, mixed>>, menu?: list<array<string, mixed>>, routes?: list<array<string, mixed>>, workflow?: list<array<string, mixed>>, settings?: list<array{namespace: string, definition: array<string, mixed>}>, featureFlags?: list<array{key: string, default: bool, module: string|null, payload: mixed}>, schema?: string, fingerprint?: string, compiler?: array<string, mixed>, modules?: list<array<string, mixed>>, extensions?: list<array<string, mixed>>, replacements?: list<array<string, mixed>>, listeners?: array<string, array<class-string, list<class-string>>>}
 *
 * In-memory result of RegistryCompiler::compile(). The compiled file
 * `bootstrap/cache/modules.php` persists this whole DTO (all 10
 * slices — the original 8 extender slices plus `settings`/`featureFlags`)
 * via {@see toArray()}/{@see fromArray()} so the runtime can project
 * entities into EntityRegistry and the morph map without re-running the
 * compiler on every request.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final readonly class CompiledRegistry
{
    public const string SCHEMA = 'packman-registry/1';

    /** @var RegistryPayload Detached scalar publication, independent of hydrated extenders. */
    private array $snapshot;

    public string $schemaVersion;

    public string $fingerprint;

    /**
     * @param  array<string, EntityDefinition>  $entities  keyed by handle
     * @param  list<CompiledEntityField>  $entityFields
     * @param  list<Permissions>  $permissions
     * @param  list<Api>  $api
     * @param  list<Filament>  $filament
     * @param  list<Menu>  $menu
     * @param  list<Routes>  $routes
     * @param  list<Workflow>  $workflow
     * @param  list<CompiledSettingDefault>  $settings  manifest-declared `DefaultSettingDefinition`s (default cascade level, not an Extender, so collected straight off `ModuleManifest::$settings`)
     * @param  list<FeatureFlagDefinition>  $featureFlags  manifest-declared flag defaults, collected off `ModuleManifest::$featureFlags`
     * @param  array<string, array<class-string, list<class-string>>>  $listeners  manifest-declared event listeners, keyed by module composer name
     * @param  string  $fingerprint  point-specific registry fingerprint read by operation contracts
     * @param  array<string, mixed>  $compiler
     * @param  list<array<string, mixed>>  $modules
     * @param  list<array<string, mixed>>  $extensions
     * @param  list<array<string, mixed>>  $replacements
     */
    public function __construct(
        public array $entities,
        public array $entityFields,
        public array $permissions,
        public array $api,
        public array $filament,
        public array $menu,
        public array $routes,
        public array $workflow,
        public array $settings = [],
        public array $featureFlags = [],
        public array $listeners = [],
        string $fingerprint = '',
        public array $compiler = [],
        public array $modules = [],
        public array $extensions = [],
        public array $replacements = [],
    ) {
        $this->schemaVersion = self::SCHEMA;
        $this->fingerprint = $fingerprint !== '' ? $fingerprint : self::hashCanonical(compiler: $compiler, modules: $modules, extensions: $extensions, replacements: $replacements);
        $this->snapshot = [
            ...$this->projectSlices(),
            'schema' => $this->schemaVersion,
            'fingerprint' => $this->fingerprint,
            'compiler' => $compiler,
            'modules' => $modules,
            'extensions' => $extensions,
            'replacements' => $replacements,
        ];
    }

    public static function empty(): self
    {
        return new self(
            entities: [],
            entityFields: [],
            permissions: [],
            api: [],
            filament: [],
            menu: [],
            routes: [],
            workflow: [],
            settings: [],
            featureFlags: [],
            listeners: [],
        );
    }

    /**
     * Plain-array projection of all 10 slices for the compiled file. Entity
     * definitions keep their handle keys; the six extender slices flatten to
     * their public scalar properties via {@see extenderToArray()} — the
     * concrete extender class per slice is fixed, so {@see fromArray()} knows
     * exactly which class to rehydrate without a discriminator.
     *
     * @return RegistryPayload
     */
    public function toArray(): array
    {
        return $this->snapshot;
    }

    /** @return RegistryPayload */
    private function projectSlices(): array
    {
        return [
            'entities' => array_map(static fn (EntityDefinition $entity): array => $entity->toArray(), $this->entities),
            'entityFields' => array_map(static fn (CompiledEntityField $field): array => $field->toArray(), $this->entityFields),
            'permissions' => array_map(self::extenderToArray(...), $this->permissions),
            'api' => array_map(self::extenderToArray(...), $this->api),
            'filament' => array_map(self::extenderToArray(...), $this->filament),
            'menu' => array_map(self::extenderToArray(...), $this->menu),
            'routes' => array_map(self::extenderToArray(...), $this->routes),
            'workflow' => array_map(self::extenderToArray(...), $this->workflow),
            'settings' => array_map(static fn (CompiledSettingDefault $setting): array => $setting->toArray(), $this->settings),
            'featureFlags' => array_map(static fn (FeatureFlagDefinition $flag): array => ['key' => $flag->key, 'default' => $flag->default, 'module' => $flag->module, 'payload' => $flag->payload === null ? null : JsonDocuments::decode($flag->payload)], $this->featureFlags),
            'listeners' => $this->listeners,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        if (! isset($data['schema'])) {
            throw CompilationException::corruptArtifact('missing schema');
        }

        if ($data['schema'] !== self::SCHEMA) {
            throw CompilationException::corruptArtifact('unknown schema');
        }

        if (! isset($data['fingerprint'])) {
            throw CompilationException::corruptArtifact('missing fingerprint');
        }

        self::assertPayload($data);
        assert(is_string($data['fingerprint']));

        $expected = self::hashCanonical(compiler: $data['compiler'] ?? [], modules: $data['modules'] ?? [], extensions: $data['extensions'] ?? [], replacements: $data['replacements'] ?? []);

        if (! hash_equals($expected, $data['fingerprint'])) {
            throw CompilationException::corruptArtifact('fingerprint mismatch');
        }

        return new self(
            entities: array_map(
                /** @param array{handle: string, model: class-string, table: string, module: string, labelSingular: string, labelPlural: string, capabilities: list<string>, fields: list<array{handle: string, type: 'string'|'int'|'decimal'|'bool'|'date'|'datetime'|'json'|'money'|'relation'|'enum', required?: bool, searchable?: bool, meta?: array<string, mixed>|null}>, titleAttribute: string|null} $entity */
                EntityDefinition::fromArray(...),
                $data['entities'] ?? [],
            ),
            entityFields: array_map(
                /** @param array{extender: array<string, mixed>, declaringModule: string, sleeping: bool} $field */
                CompiledEntityField::fromArray(...),
                $data['entityFields'] ?? [],
            ),
            permissions: array_map(static fn (array $row): Permissions => self::extenderFromArray(class: Permissions::class, data: $row), $data['permissions'] ?? []),
            api: array_map(static fn (array $row): Api => self::extenderFromArray(class: Api::class, data: $row), $data['api'] ?? []),
            filament: array_map(static fn (array $row): Filament => self::extenderFromArray(class: Filament::class, data: $row), $data['filament'] ?? []),
            menu: array_map(static fn (array $row): Menu => self::extenderFromArray(class: Menu::class, data: $row), $data['menu'] ?? []),
            routes: array_map(static fn (array $row): Routes => self::extenderFromArray(class: Routes::class, data: $row), $data['routes'] ?? []),
            workflow: array_map(static fn (array $row): Workflow => self::extenderFromArray(class: Workflow::class, data: $row), $data['workflow'] ?? []),
            settings: array_map(
                /** @param  array{namespace: string, definition: array<string, mixed>}  $setting */
                CompiledSettingDefault::fromArray(...),
                $data['settings'] ?? [],
            ),
            featureFlags: array_map(self::featureFlagFromArray(...), $data['featureFlags'] ?? []),
            listeners: $data['listeners'] ?? [],
            fingerprint: $data['fingerprint'],
            compiler: $data['compiler'] ?? [],
            modules: $data['modules'] ?? [],
            extensions: $data['extensions'] ?? [],
            replacements: $data['replacements'] ?? [],
        );
    }

    /**
     * @param  array<array-key, mixed>  $data
     *
     * @phpstan-assert RegistryPayload $data
     */
    private static function assertPayload(array $data): void
    {
        foreach (['schema', 'fingerprint'] as $key) {
            if (isset($data[$key]) && ! is_string($data[$key])) {
                throw CompilationException::corruptArtifact($key);
            }
        }
        foreach (['permissions', 'api', 'filament', 'menu', 'routes', 'workflow', 'modules', 'extensions', 'replacements'] as $key) {
            if (! self::rows(value: $data[$key] ?? [], valid: self::map(...))) {
                throw CompilationException::corruptArtifact($key);
            }
        }

        if (! self::map($data['compiler'] ?? [])) {
            throw CompilationException::corruptArtifact('compiler');
        }
        $entities = $data['entities'] ?? [];

        if (! self::map($entities)) {
            throw CompilationException::corruptArtifact('entities');
        }
        foreach ($entities as $row) {
            if (! self::map($row)) {
                throw CompilationException::corruptArtifact('entity');
            }
            foreach (['handle', 'model', 'table', 'module', 'labelSingular', 'labelPlural'] as $key) {
                if (! is_string($row[$key] ?? null)) {
                    throw CompilationException::corruptArtifact('entity.'.$key);
                }
            }

            if ((! class_exists($row['model']) && ! interface_exists($row['model'])) || ! self::strings($row['capabilities'] ?? null)
                || ! self::rows(value: $row['fields'] ?? null, valid: static fn (mixed $field): bool => self::map($field) && is_string($field['handle'] ?? null)
                    && in_array($field['type'] ?? null, ['string', 'int', 'decimal', 'bool', 'date', 'datetime', 'json', 'money', 'relation', 'enum'], true)
                    && (! isset($field['required']) || is_bool($field['required']))
                    && (! isset($field['searchable']) || is_bool($field['searchable']))
                    && (! isset($field['meta']) || self::map($field['meta']))) || ! array_key_exists('titleAttribute', $row)
                || ($row['titleAttribute'] !== null && ! is_string($row['titleAttribute']))) {
                throw CompilationException::corruptArtifact('entity shape');
            }
        }

        if (! self::rows(value: $data['entityFields'] ?? [], valid: static fn (mixed $row): bool => self::map($row)
            && self::map($row['extender'] ?? null) && is_string($row['declaringModule'] ?? null) && is_bool($row['sleeping'] ?? null))) {
            throw CompilationException::corruptArtifact('entityFields');
        }

        if (! self::rows(value: $data['settings'] ?? [], valid: static fn (mixed $row): bool => self::map($row)
            && is_string($row['namespace'] ?? null) && self::map($row['definition'] ?? null))) {
            throw CompilationException::corruptArtifact('settings');
        }

        if (! self::rows(value: $data['featureFlags'] ?? [], valid: static fn (mixed $row): bool => self::map($row)
            && is_string($row['key'] ?? null) && is_bool($row['default'] ?? null)
            && array_key_exists('module', $row) && ($row['module'] === null || is_string($row['module']))
            && array_key_exists('payload', $row))) {
            throw CompilationException::corruptArtifact('featureFlags');
        }
        $listeners = $data['listeners'] ?? [];

        if (! self::map($listeners)) {
            throw CompilationException::corruptArtifact('listeners');
        }
        foreach ($listeners as $events) {
            if (! self::map($events)) {
                throw CompilationException::corruptArtifact('listener events');
            }
            foreach ($events as $event => $classes) {
                if ((! class_exists($event) && ! interface_exists($event)) || ! self::strings($classes)
                    || ! array_all($classes, static fn (string $class): bool => class_exists($class) || interface_exists($class))) {
                    throw CompilationException::corruptArtifact('listener classes');
                }
            }
        }
    }

    /** @phpstan-assert-if-true array<string, mixed> $value */
    private static function map(mixed $value): bool
    {
        return is_array($value) && array_all(array_keys($value), static fn (mixed $key): bool => is_string($key));
    }

    /** @phpstan-assert-if-true list<string> $value */
    private static function strings(mixed $value): bool
    {
        return is_array($value) && array_is_list($value) && array_all($value, static fn (mixed $item): bool => is_string($item));
    }

    /** @param Closure(mixed): bool $valid */
    private static function rows(mixed $value, Closure $valid): bool
    {
        return is_array($value) && array_is_list($value) && array_all($value, $valid);
    }

    /**
     * @param  array<string, mixed>  $compiler
     * @param  list<array<string, mixed>>  $modules
     * @param  list<array<string, mixed>>  $extensions
     * @param  list<array<string, mixed>>  $replacements
     */
    public static function hashCanonical(array $compiler, array $modules, array $extensions, array $replacements): string
    {
        $payload = self::canonical([
            'schema' => self::SCHEMA,
            'compiler' => $compiler,
            'modules' => $modules,
            'extensions' => $extensions,
            'replacements' => $replacements,
        ]);

        return hash('sha256', self::SCHEMA."\0".json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::canonical(...), $value);
    }

    /** @param  array{key: string, default: bool, module: string|null, payload: mixed}  $data */
    private static function featureFlagFromArray(array $data): FeatureFlagDefinition
    {
        return new FeatureFlagDefinition(
            key: $data['key'],
            default: $data['default'],
            module: $data['module'],
            payload: JsonDocuments::nullable($data['payload']),
        );
    }

    /**
     * Flatten an extender to its public scalar/array properties. Every
     * extender in the closed set holds only scalars and arrays-of-scalars
     * (class-strings stay strings) — none nest objects — so
     * `get_object_vars()` yields a var_export'able array with no
     * `__set_state` and no closures. Shared by {@see CompiledEntityField}.
     *
     * @return array<string, mixed>
     */
    public static function extenderToArray(Extender $extender): array
    {
        $values = [];
        foreach (get_object_vars($extender) as $property => $value) {
            $values[(string) $property] = $value;
        }

        return $values;
    }

    /**
     * Rebuild an extender from its flattened array. Reconstruction is by
     * property assignment (not the private factory methods, which the runtime
     * has no data to feed) — the inverse of {@see extenderToArray()}, and the
     * only reason `__set_state` is not needed in the persisted file.
     *
     * @template T of \Packman\Modules\Contracts\Compiler\Extender
     *
     * @param  class-string<T>  $class
     * @param  array<string, mixed>  $data
     * @return T
     */
    public static function extenderFromArray(string $class, array $data): Extender
    {
        $reflection = new ReflectionClass($class);
        $extender = $reflection->newInstanceWithoutConstructor();

        foreach ($data as $property => $value) {
            if ($reflection->hasProperty($property)) {
                $reflection->getProperty($property)->setValue($extender, $value);
            }
        }

        return $extender;
    }
}
