<?php

declare(strict_types=1);

namespace Packman\Plugins\Internal\Graph;

use Packman\Attributes\Internal;
use Packman\Extensions\Data\ExtensionFacetProfile;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;

/** One instantiated plugin during graph compilation. */
#[Internal]
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
