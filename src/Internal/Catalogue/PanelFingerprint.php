<?php

declare(strict_types=1);

namespace Packman\Panels\Internal\Catalogue;

use Closure;
use Packman\Attributes\Internal;
use Packman\Panels\Builder\Panel;
use Packman\Panels\Data\Admission\PermissionDefinition;

/**
 * Deterministic SHA-256 fingerprint of a compiled panel's normalized recipe metadata.
 */
#[Internal]
final class PanelFingerprint
{
    public static function of(Panel $panel, PanelRecipe $recipe): string
    {
        return hash('sha256', json_encode(
            self::canonical(self::metadata($panel, $recipe)),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public static function metadata(Panel $panel, PanelRecipe $recipe): array
    {
        $hooks = [];

        foreach (PanelRecipe::HOOK_LISTS as $list) {
            $hooks[$list] = array_map(self::name(...), PanelCompiler::items($recipe, $list));
        }

        $options = array_map(static fn (object $options): mixed => CanonicalMetadata::metadata($options), $panel->optionObjects());

        $slots = [];
        foreach ($panel->slots() as $id => $slot) {
            $slots[$id] = [
                'settings' => $slot->settings,
                'defaults' => $slot->defaults,
                'inherit_defaults' => $slot->inheritDefaults,
            ];
        }

        return [
            'id' => $panel->id(),
            'prefix' => $panel->prefix(),
            'default' => $panel->isDefault(),
            'settings' => array_map(
                static fn (array $setting): bool|int|string|null => $setting['value'],
                $panel->settings()->toArray(),
            ),
            'subjects' => $recipe->subjects(),
            'enums' => $recipe->enums(),
            'roles' => $recipe->roles(),
            'plugins' => $panel->pluginIds(),
            'sources' => array_map(
                static fn (PermissionDefinition|string $source): array => $source instanceof PermissionDefinition
                    ? ['local' => $source->local, 'authority' => $source->authority]
                    : ['name' => $source],
                $recipe->sources(),
            ),
            'hooks' => $hooks,
            'tenant_mode' => $panel->tenantMode()->value,
            'scope_mode' => $panel->scopeMode()->value,
            'scope_types' => $panel->scopeTypes(),
            'subject_resolver' => $panel->subjectResolver(),
            'required_capabilities' => $panel->requiredCapabilities(),
            ...($slots === [] ? [] : ['slots' => $slots]),
            ...($options === [] ? [] : ['options' => $options]),
        ];
    }

    private static function name(mixed $item): string
    {
        return match (true) {
            $item instanceof Closure => 'closure',
            is_object($item) => $item::class,
            is_string($item) => $item,
            default => get_debug_type($item),
        };
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
}
