<?php

declare(strict_types=1);

namespace Packman\Modules\Data\Compiler;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Modules\Contracts\Lifecycle\ModuleLifecycle;
use Packman\Modules\Data\Lifecycle\ModuleDependency;
use Packman\Modules\Data\Lifecycle\ModuleManifest;

/**
 * One module's compiled-registry entry (`ModuleRegistry::get()`): manifest
 * metadata needed at the hot path, serialized as plain scalars/arrays so it
 * round-trips through `var_export()` atomic write.
 *
 * Compiled extender output (entities, permissions, menu, api, filament,
 * routes, workflow — {@see CompiledRegistry}) is persisted separately in the
 * same compiled file under its own `registry` key. This entry keeps the
 * per-module manifest metadata the hot path and lifecycle need: identity,
 * table prefix, dependency/edition/component maps and the terminal-transition
 * hooks {@see ModuleLifecycle} fires.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final readonly class CompiledModule
{
    /**
     * @param  list<array{kind: string, package: string, constraint: string}>  $requires
     * @param  array<string, list<string>>  $editions
     * @param  list<class-string>  $components
     * @param  class-string|null  $onEnableHook
     * @param  class-string|null  $onDisableHook
     * @param  class-string|null  $onPurgeHook
     */
    public function __construct(
        public string $composerName,
        public ?string $title,
        public ?string $tablePrefix,
        public array $requires,
        public array $editions,
        public array $components,
        public ?string $onEnableHook = null,
        public ?string $onDisableHook = null,
        public ?string $onPurgeHook = null,
    ) {}

    public static function fromManifest(ModuleManifest $manifest): self
    {
        return new self(
            composerName: $manifest->composerName,
            title: $manifest->title,
            tablePrefix: $manifest->tablePrefix,
            requires: array_map(
                static fn (ModuleDependency $dep): array => ['kind' => $dep->kind, 'package' => $dep->package, 'constraint' => $dep->constraint],
                $manifest->requires,
            ),
            editions: $manifest->editions,
            components: $manifest->components,
            onEnableHook: $manifest->onEnableHook,
            onDisableHook: $manifest->onDisableHook,
            onPurgeHook: $manifest->onPurgeHook,
        );
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        self::assertPayload($data);

        return new self(
            composerName: $data['composerName'],
            title: $data['title'],
            tablePrefix: $data['tablePrefix'],
            requires: $data['requires'],
            editions: $data['editions'],
            components: $data['components'],
            onEnableHook: $data['onEnableHook'] ?? null,
            onDisableHook: $data['onDisableHook'] ?? null,
            onPurgeHook: $data['onPurgeHook'] ?? null,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @phpstan-assert array{composerName: string, title: ?string, tablePrefix: ?string, requires: list<array{kind: string, package: string, constraint: string}>, editions: array<string, list<string>>, components: list<class-string>, onEnableHook?: class-string|null, onDisableHook?: class-string|null, onPurgeHook?: class-string|null} $data
     */
    private static function assertPayload(array $data): void
    {
        assert(is_string($data['composerName']));
        assert($data['title'] === null || is_string($data['title']));
        assert($data['tablePrefix'] === null || is_string($data['tablePrefix']));
        assert(is_array($data['requires']) && array_is_list($data['requires']));
        foreach ($data['requires'] as $row) {
            assert(is_array($row) && is_string($row['kind']) && is_string($row['package']) && is_string($row['constraint']));
        }
        assert(is_array($data['editions']));
        foreach ($data['editions'] as $edition => $capabilities) {
            assert(is_string($edition) && is_array($capabilities) && array_is_list($capabilities));
            foreach ($capabilities as $capability) {
                assert(is_string($capability));
            }
        }
        assert(is_array($data['components']) && array_is_list($data['components']));
        foreach ($data['components'] as $component) {
            assert(is_string($component) && (class_exists($component) || interface_exists($component)));
        }
        foreach (['onEnableHook', 'onDisableHook', 'onPurgeHook'] as $key) {
            $hook = $data[$key] ?? null;
            assert($hook === null || (is_string($hook) && (class_exists($hook) || interface_exists($hook))));
        }
    }

    /**
     * @return array{composerName: string, title: ?string, tablePrefix: ?string, requires: list<array{kind: string, package: string, constraint: string}>, editions: array<string, list<string>>, components: list<class-string>, onEnableHook: class-string|null, onDisableHook: class-string|null, onPurgeHook: class-string|null}
     */
    public function toArray(): array
    {
        return [
            'composerName' => $this->composerName,
            'title' => $this->title,
            'tablePrefix' => $this->tablePrefix,
            'requires' => $this->requires,
            'editions' => $this->editions,
            'components' => $this->components,
            'onEnableHook' => $this->onEnableHook,
            'onDisableHook' => $this->onDisableHook,
            'onPurgeHook' => $this->onPurgeHook,
        ];
    }
}
