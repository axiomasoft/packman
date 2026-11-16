<?php

declare(strict_types=1);

namespace Packman\Modules\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Modules\Data\CompiledRegistry;
use Packman\Modules\Data\InstalledPackageVersion;
use Packman\Modules\Data\ModuleManifest;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ModuleCompilation
{
    /**
     * @param  list<ModuleManifest>  $manifests
     * @param  list<string>|null  $installedPackages  composer names known installed beyond
     *                                                $manifests. Defaults to a real read of
     *                                                `vendor/composer/installed.json` ({@see
     *                                                InstalledPackages}) — pass an explicit list in
     *                                                tests/testbench to avoid depending on the host's
     *                                                installed.json contents.
     * @param  (callable(class-string): void)|null  $onStage  observes each {@see PipelineStages}
     *                                                        entry as its stage starts, in the order
     *                                                        actually executed — test seam for
     *                                                        pipeline determinism.
     * @param  array<string, InstalledPackageVersion>|null  $installedVersions  Explicit
     *                                                                          metadata for contract fixtures.
     *
     * @internal spec: AC-2, AC-18
     */
    public function compile(array $manifests, ?array $installedPackages = null, ?callable $onStage = null, ?array $installedVersions = null): CompiledRegistry;
}
