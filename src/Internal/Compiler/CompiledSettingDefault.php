<?php

declare(strict_types=1);

namespace Packman\Modules\Internal\Compiler;

use Packman\Modules\Internal\Settings\DefaultSettingDefinition;
use ReflectionClass;

/**
 * A manifest-declared {@see DefaultSettingDefinition} paired with its owning module's
 * setting-namespace — `SettingDefaultsProviderInterface`/`ModuleManifest::$settings` never
 * reached the compiled registry before this. The namespace is the module's
 * `tablePrefix`: `sys_settings.namespace` is documented as "id модуля
 * ('crm')", which is exactly the short 2–3 letter code
 * {@see Packman\Modules\Data\Lifecycle\ModuleManifest::$tablePrefix} already carries, not the full
 * composer name.
 *
 * @internal spec: P1.25, A68, B-10 §3.1
 */
final readonly class CompiledSettingDefault
{
    public function __construct(
        public string $namespace,
        public DefaultSettingDefinition $definition,
    ) {}

    /**
     * `DefaultSettingDefinition` has a private constructor (fluent factory, see the
     * class) — reconstruction goes through reflection like
     * {@see CompiledRegistry::extenderFromArray()}, just not constrained to
     * the `Extender` interface.
     *
     * @return array{namespace: string, definition: array<string, mixed>}
     */
    public function toArray(): array
    {
        $properties = [];
        foreach (get_object_vars($this->definition) as $property => $value) {
            $properties[(string) $property] = $value;
        }

        return [
            'namespace' => $this->namespace,
            'definition' => $properties,
        ];
    }

    /**
     * @param  array{namespace: string, definition: array<string, mixed>}  $data
     */
    public static function fromArray(array $data): self
    {
        $reflection = new ReflectionClass(DefaultSettingDefinition::class);
        /** @var DefaultSettingDefinition $definition */
        $definition = $reflection->newInstanceWithoutConstructor();

        foreach ($data['definition'] as $property => $value) {
            if ($reflection->hasProperty($property)) {
                $reflection->getProperty($property)->setValue($definition, $value);
            }
        }

        return new self(namespace: $data['namespace'], definition: $definition);
    }
}
