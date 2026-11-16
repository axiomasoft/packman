<?php

declare(strict_types=1);

namespace Packman\Modules\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Modules\Data\Api;
use Packman\Modules\Data\ModuleManifest;
use Packman\Modules\Data\NormalizedExtension;

/** Packages own compilation and normalization of their API declaration modes. */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ManifestSectionCompiler
{
    public function mode(): string;

    public function phase(): int;

    /**
     * @param  list<Api>  $declarations
     * @param  list<Api>  $all
     */
    public function compile(array $declarations, array $all): void;

    /** @return list<NormalizedExtension> */
    public function normalize(ModuleManifest $manifest, Api $api, string $ownerVersion): array;
}
