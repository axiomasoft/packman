<?php

declare(strict_types=1);

namespace Packman\Plugins\Data\Plugin;

use Packman\Attributes\Api;
use Packman\Extensions\Data\ExtensionFacetProfile;
use Packman\Plugins\Data\Contributions\ContributionDescription;
use Packman\Plugins\Data\Contributions\ContributionKind;
use Packman\Plugins\Enums\Plugin\PluginSource;

#[Api(since: '1.0')]
final readonly class PluginDescription
{
    /**
     * @param  class-string  $class
     * @param  list<string>  $requires
     * @param  list<string>  $optional
     * @param  list<ContributionKind>  $kinds
     * @param  list<ContributionDescription>  $contributions
     */
    public function __construct(
        public string $id,
        public string $version,
        public PluginSource $source,
        public string $class,
        public array $requires,
        public array $optional,
        public array $kinds,
        public array $contributions,
        public ?ExtensionFacetProfile $facets = null,
    ) {}
}
