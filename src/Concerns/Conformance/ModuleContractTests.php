<?php

declare(strict_types=1);

namespace Packman\Testkit\Concerns\Conformance;

use Packman\Attributes\Api;
use Packman\Extensions\Contracts\Configuration\WithConfiguration;
use Packman\Extensions\ExtensionInspector;
use Packman\Modules\Contracts\Definition\ModuleDefinition;
use Packman\Modules\Data\Compiler\InstalledPackageVersion;
use Packman\Modules\Testing\ExtensionContract;
use PHPUnit\Framework\Assert;
use RuntimeException;

/**
 * Reusable conformance assertions for ModuleDefinition implementations.
 */
#[Api(since: '1.0')]
trait ModuleContractTests
{
    abstract protected function moduleUnderTest(): ModuleDefinition;

    /** @return array<string, InstalledPackageVersion> */
    protected function installedExtensionPackages(): array
    {
        $composerName = $this->moduleUnderTest()->manifest()->composerName;

        return [
            $composerName => new InstalledPackageVersion(name: $composerName, version: '1.0.0', reference: null),
        ];
    }

    public function test_module_manifest_passes_extension_contract_and_facet_inspection(): void
    {
        if (! class_exists(ExtensionContract::class)) {
            throw new RuntimeException('axiomasoft/packman-modules is required to execute ModuleContractTests.');
        }

        $module = $this->moduleUnderTest();
        $manifest = $module->manifest();

        Assert::assertStringContainsString('/', $manifest->composerName);

        $report = ExtensionContract::verify(
            manifests: [$manifest],
            installedPackages: $this->installedExtensionPackages(),
        );
        Assert::assertTrue($report->passed(), $report->summary());

        $profile = ExtensionInspector::inspect($module);

        if ($module instanceof WithConfiguration) {
            Assert::assertTrue($profile->hasConfiguration);
        }
    }
}
