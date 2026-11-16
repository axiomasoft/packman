<?php

declare(strict_types=1);

namespace Packman\Panels\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Packman\Attributes\Api;
use Packman\Extensions\Contracts\Composition\WithPanels;
use Packman\Extensions\ExtensionInspector;
use Packman\Panels\Builder\PanelProvider as BuilderPanelProvider;
use Packman\Panels\Contracts\Registry\PanelProvider;
use Packman\Panels\Contracts\Registry\PanelRegistry;
use Packman\Panels\Exceptions\Definition\DefinitionException;
use Packman\Panels\Internal\Configuration\PanelsOptions;
use WeakMap;

/**
 * Registers panel providers declared by {@see WithPanels} consumers and `ExtensionInspector` profiles.
 */
#[Api(since: '1.0')]
final class PanelExtensionBridge
{
    public static function registerExtension(Application $app, object $extension): void
    {
        $profile = ExtensionInspector::inspect($extension);

        if ($profile->panelProviders === []) {
            return;
        }

        self::registerProviders($app, $profile->panelProviders);
    }

    /**
     * @param  list<class-string>  $providerClasses
     *
     * @throws DefinitionException
     */
    public static function registerProviders(Application $app, array $providerClasses): void
    {
        if ($providerClasses === []) {
            return;
        }

        $tag = $app->make(PanelsOptions::class)->providerTag;
        $registry = $app->bound(PanelRegistry::class) ? $app->make(PanelRegistry::class) : null;

        foreach ($providerClasses as $providerClass) {
            if (is_subclass_of($providerClass, BuilderPanelProvider::class)) {
                if ($registry !== null && ! $registry->isFrozen()) {
                    $registry->register($providerClass);
                }

                continue;
            }

            if (is_subclass_of($providerClass, PanelProvider::class)) {
                $app->singletonIf($providerClass);

                if (self::markTagged($app, $tag, $providerClass)) {
                    $app->tag([$providerClass], $tag);
                }

                continue;
            }

            throw new DefinitionException(
                'WithPanels provider ['.$providerClass.'] must implement '.PanelProvider::class.' or extend '.BuilderPanelProvider::class.'.',
            );
        }
    }

    /**
     * @param  class-string  $providerClass
     */
    private static function markTagged(Application $app, string $tag, string $providerClass): bool
    {
        /** @var WeakMap<Application, array<string, array<class-string, true>>>|null $tagged */
        static $tagged = null;

        if (! $tagged instanceof WeakMap) {
            /** @var WeakMap<Application, array<string, array<class-string, true>>> $map */
            $map = new WeakMap;
            $tagged = $map;
        }

        /** @var array<string, array<class-string, true>> $byTag */
        $byTag = $tagged->offsetExists($app) ? $tagged->offsetGet($app) : [];

        if (isset($byTag[$tag][$providerClass])) {
            return false;
        }

        $byTag[$tag][$providerClass] = true;
        $tagged->offsetSet($app, $byTag);

        return true;
    }
}
