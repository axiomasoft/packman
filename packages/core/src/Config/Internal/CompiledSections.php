<?php

declare(strict_types=1);

namespace Packman\Config\Internal;

use LogicException;
use Packman\Attributes\Internal;
use Packman\Config\Contracts\SettingsLookup;

#[Internal]
final readonly class CompiledSections implements SettingsLookup
{
    /**
     * @param  array<class-string, object>  $settings
     * @param  list<array<string, mixed>>  $schema
     * @param  list<string>  $unsafePaths
     */
    public function __construct(
        public array $settings,
        public array $schema,
        public array $unsafePaths,
    ) {}

    /**
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return T
     */
    public function get(string $class): object
    {
        $settings = $this->settings[$class] ?? throw new LogicException("No compiled config section produces {$class}.");

        /** @var T $settings */
        return $settings;
    }
}
