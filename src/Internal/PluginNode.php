<?php

declare(strict_types=1);

namespace Packman\Plugins\Internal;

use Packman\Extensions\Data\ExtensionFacetProfile;
use Packman\Plugins\Contracts\ExtensionPlugin;
use Packman\Plugins\Data\PluginManifest;

/** One instantiated plugin during graph compilation. */
final readonly class PluginNode
{
    public function __construct(
        public PluginEntry $entry,
        public ExtensionPlugin $plugin,
        public PluginManifest $manifest,
        public PluginDeclarations $declarations,
        public ExtensionFacetProfile $facets,
    ) {}
}
