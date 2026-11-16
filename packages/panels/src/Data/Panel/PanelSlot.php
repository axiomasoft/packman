<?php

declare(strict_types=1);

namespace Packman\Panels\Data\Panel;

use Packman\Attributes\Api;
use Packman\Panels\Exceptions\Definition\DefinitionException;

/**
 * Declarative settings slot inside a panel, with optional defaults and inheritance policy.
 */
#[Api(since: '1.0')]
final readonly class PanelSlot
{
    /** @var list<string> */
    public array $settings;

    /**
     * @param  array<array-key, mixed>  $settings
     * @param  array<string, mixed>  $defaults
     */
    public function __construct(
        public string $id,
        array $settings,
        public array $defaults = [],
        public bool $inheritDefaults = true,
    ) {
        self::identifier($id);

        if (! array_is_list($settings)) {
            throw new DefinitionException('Slot settings must be a list of unique IDs.');
        }

        $normalized = [];

        foreach ($settings as $setting) {
            if (! is_string($setting) || $setting === '') {
                throw new DefinitionException('Slot settings must be non-empty strings.');
            }

            $normalized[] = $setting;
        }

        if (count(array_unique($normalized)) !== count($normalized)) {
            throw new DefinitionException('Slot settings must be unique IDs.');
        }

        $this->settings = $normalized;
    }

    public static function identifier(string $id): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9_.:-]{0,127}$/D', $id) !== 1) {
            throw new DefinitionException('Invalid definition ID.');
        }
    }
}
