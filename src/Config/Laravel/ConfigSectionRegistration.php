<?php

declare(strict_types=1);

namespace Packman\Config\Laravel;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Config\Contracts\ConfigSection;
use Packman\Config\Internal\ConfigSections;
use Packman\Config\Internal\SectionRegistrar;
use Packman\Doctor\Checks\ConfigValidCheck;

/**
 * Supported SPI entry point for registering config sections, root ownership, and doctor config validators.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class ConfigSectionRegistration
{
    /** @param class-string<ConfigSection> ...$sections */
    public static function register(Application $app, string ...$sections): void
    {
        SectionRegistrar::register($app, ...$sections);
    }

    /** @param class-string<ConfigSection> ...$sections */
    public static function bindSettings(Application $app, string ...$sections): void
    {
        SectionRegistrar::bindSettings($app, ...$sections);
    }

    /** @param Closure(array<string, mixed>): void $validate */
    public static function own(Application $app, string $root, Closure $validate): void
    {
        $app->make(ConfigSections::class)->own($root, $validate);
    }

    /** @param array<string, mixed> $config */
    public static function assertKnownKeys(Application $app, array $config): void
    {
        $app->make(ConfigSections::class)->assertKnownKeys($config);
    }

    /**
     * Registers a configuration validation closure executed by {@see ConfigValidCheck}.
     *
     * @param  Closure(): void  $validator
     */
    public static function registerValidator(Application $app, string $id, Closure $validator): void
    {
        $binding = ConfigValidCheck::TAG.':'.$id;

        if ($app->bound($binding)) {
            return;
        }

        $app->instance($binding, $validator);
        $app->tag([$binding], ConfigValidCheck::TAG);
    }
}
