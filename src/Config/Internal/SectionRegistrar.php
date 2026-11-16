<?php

declare(strict_types=1);

namespace Packman\Config\Internal;

use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;
use Packman\Attributes\Internal;
use Packman\Config\Contracts\ConfigSection;
use Packman\Config\Contracts\SettingsLookup;

/**
 * Registers config sections in `ServiceProvider::register()` and binds each section's settings class
 * against the bound {@see SettingsLookup} container entry.
 */
#[Internal]
final class SectionRegistrar
{
    public const string TAG = 'packman.config-sections';

    private const string REGISTERED = 'packman.config-sections.registered';

    /** @param class-string ...$sections */
    public static function register(Application $app, string ...$sections): void
    {
        $registered = $app->bound(self::REGISTERED) ? $app->make(self::REGISTERED) : [];

        if (! is_array($registered)) {
            throw new InvalidArgumentException('Invalid registered config sections.');
        }

        foreach ($sections as $class) {
            if (isset($registered[$class])) {
                continue;
            }

            if (! is_a($class, ConfigSection::class, true)) {
                throw new InvalidArgumentException("{$class} is not a ConfigSection.");
            }

            $settings = (new $class)->settingsClass();
            $app->tag([$class], self::TAG);
            $app->bind($settings, static fn (Application $app): object => $app->make(SettingsLookup::class)->get($settings));
            $registered[$class] = true;
        }

        $app->instance(self::REGISTERED, $registered);
    }

    /** @param class-string ...$sections */
    public static function bindSettings(Application $app, string ...$sections): void
    {
        foreach ($sections as $class) {
            if (! is_a($class, ConfigSection::class, true)) {
                throw new InvalidArgumentException("{$class} is not a ConfigSection.");
            }

            $settings = (new $class)->settingsClass();

            if (! $app->bound($settings)) {
                $app->bind($settings, static fn (Application $app): object => $app->make(SettingsLookup::class)->get($settings));
            }
        }
    }
}
