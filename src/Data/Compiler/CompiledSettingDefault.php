<?php

declare(strict_types=1);

namespace Packman\Modules\Data\Compiler;

use Packman\Attributes\Api;
use Packman\Modules\Data\Settings\DefaultSettingDefinition;
use Packman\Modules\Exceptions\Compiler\CompilationException;

/** A compiled setting default paired with the namespace declared by its module. */
#[Api(since: '0.1.1')]
final readonly class CompiledSettingDefault
{
    public function __construct(
        public string $namespace,
        public DefaultSettingDefinition $definition,
    ) {}

    /** @return array{namespace: string, definition: array<string, mixed>} */
    public function toArray(): array
    {
        return [
            'namespace' => $this->namespace,
            'definition' => [
                'key' => $this->definition->key,
                'default' => $this->definition->default,
                'workspaceScoped' => $this->definition->workspaceScoped,
                'userScoped' => $this->definition->userScoped,
                'sensitive' => $this->definition->sensitive,
            ],
        ];
    }

    /** @param array{namespace: string, definition: array<string, mixed>} $data */
    public static function fromArray(array $data): self
    {
        $fields = $data['definition'] + ['workspaceScoped' => false, 'userScoped' => false, 'sensitive' => false];

        if (! is_string($fields['key'] ?? null) || ! array_key_exists('default', $fields)
            || ! is_bool($fields['workspaceScoped']) || ! is_bool($fields['userScoped']) || ! is_bool($fields['sensitive'])) {
            throw CompilationException::corruptArtifact('settings.definition');
        }

        return new self($data['namespace'], new DefaultSettingDefinition(
            key: $fields['key'],
            default: $fields['default'],
            workspaceScoped: $fields['workspaceScoped'],
            userScoped: $fields['userScoped'],
            sensitive: $fields['sensitive'],
        ));
    }
}
