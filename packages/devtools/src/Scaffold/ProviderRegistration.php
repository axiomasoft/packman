<?php

declare(strict_types=1);

namespace Packman\DevTools\Scaffold;

use Packman\Attributes\Api;

/** Registry-aware helper formatting PSR-4 composer/provider registrations cleanly without duplicate entries. */
#[Api(since: '1.0')]
final class ProviderRegistration
{
    public static function registerInComposerJson(string $composerJsonContent, Place $place): string
    {
        $decoded = json_decode($composerJsonContent, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            return $composerJsonContent;
        }

        if (! is_array($decoded['autoload'] ?? null)) {
            $decoded['autoload'] = [];
        }

        if (! is_array($decoded['autoload']['psr-4'] ?? null)) {
            $decoded['autoload']['psr-4'] = [];
        }

        $namespaceKey = rtrim($place->namespace, '\\').'\\';
        $decoded['autoload']['psr-4'][$namespaceKey] = $place->relativeDirectory.'/src/';
        ksort($decoded['autoload']['psr-4'], SORT_STRING);

        return json_encode($decoded, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    }
}
