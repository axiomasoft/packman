<?php

declare(strict_types=1);

namespace Packman\Modules\Internal\Settings;

use Override;
use Packman\Attributes\Internal;
use Packman\Modules\Contracts\Registry\ModuleRegistry;
use Packman\Modules\Data\Compiler\CompiledSettingDefault;
use Packman\Settings\Contracts\SettingDefaultsProviderInterface;

/**
 * Real {@see SettingDefaultsProviderInterface} backed by the compiled module registry.
 *
 * Reads manifest-declared {@see DefaultSettingDefinition}s off the compiled registry
 * ({@see ModuleRegistry::compiled()}) rather than re-running `ModuleDiscovery::discover()` on hot reads.
 *
 * @internal spec: D20/D22/D23, P1.15, P1.25
 */
#[Internal]
final class CompiledSettingDefaultsProvider implements SettingDefaultsProviderInterface
{
    /** @var array<string, array<string, CompiledSettingDefault>>|null keyed by [namespace][key], built once per instance */
    private ?array $index = null;

    public function __construct(private readonly ModuleRegistry $registry) {}

    #[Override]
    public function defaultFor(string $namespace, string $key): mixed
    {
        return $this->index()[$namespace][$key]->definition->default ?? null;
    }

    #[Override]
    public function isSensitive(string $namespace, string $key): bool
    {
        return $this->index()[$namespace][$key]->definition->sensitive ?? false;
    }

    /** @return array<string, array<string, CompiledSettingDefault>> */
    private function index(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }

        $index = [];

        foreach ($this->registry->compiled()->settings as $setting) {
            $index[$setting->namespace][$setting->definition->key] = $setting;
        }

        return $this->index = $index;
    }
}
