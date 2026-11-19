<?php

declare(strict_types=1);

namespace Packman\DevTools\Gates\Runner;

use Packman\Attributes\Api;
use Packman\DevTools\Contracts\Gates\QualityGate;
use Packman\DevTools\Data\Gates\GateContext;
use Packman\DevTools\Data\Gates\GateResult;
use Packman\DevTools\Gates\Architecture\AttributesArchitectureGate;
use Packman\DevTools\Gates\Architecture\ModuleLayoutGate;
use Packman\DevTools\Gates\Architecture\OwnershipGate;
use Packman\DevTools\Gates\Architecture\PackageGraphGate;
use Packman\DevTools\Gates\Boundaries\ConfigCompositionGate;
use Packman\DevTools\Gates\Boundaries\ProviderSizeGate;
use Packman\DevTools\Gates\Boundaries\RuntimeGlobalsGate;
use Packman\DevTools\Gates\Boundaries\TableOwnershipGate;
use Packman\DevTools\Gates\Boundaries\TableWriteOwnershipGate;
use Packman\DevTools\Gates\Contracts\ErrorRegistryGate;
use Packman\DevTools\Gates\Contracts\ExtensionContractGate;
use Packman\DevTools\Gates\Contracts\PhpApiGate;
use Packman\DevTools\Gates\Contracts\SchemaGate;
use Packman\DevTools\Gates\Quality\NamingGate;
use Packman\DevTools\Gates\Quality\PackageDocsGate;
use Packman\DevTools\Gates\Quality\QualityCeilingGuard;
use Packman\DevTools\Internal\ProjectAutoload;

/**
 * Executes the 16 registered Packman quality gates against a monorepo workspace.
 */
#[Api(since: '1.0')]
final readonly class GateRunner
{
    /** @param list<QualityGate> $gates */
    public function __construct(
        private array $gates = [],
        private PackmanManifestReader $manifestReader = new PackmanManifestReader,
    ) {}

    public static function default(): self
    {
        return new self([
            new PackageGraphGate,
            new ModuleLayoutGate,
            new OwnershipGate,
            new AttributesArchitectureGate,
            new ConfigCompositionGate,
            new RuntimeGlobalsGate,
            new ProviderSizeGate,
            new TableOwnershipGate,
            new TableWriteOwnershipGate,
            new PhpApiGate,
            new ErrorRegistryGate,
            new ExtensionContractGate,
            new SchemaGate,
            new PackageDocsGate,
            new NamingGate,
            new QualityCeilingGuard,
        ]);
    }

    /** @return list<QualityGate> */
    public function gates(): array
    {
        return $this->gates;
    }

    /**
     * @param  list<string>  $onlyIds
     * @return list<GateResult>
     */
    public function run(string $rootPath, array $onlyIds = []): array
    {
        return ProjectAutoload::run($rootPath, fn (): array => $this->inspect($rootPath, $onlyIds));
    }

    /**
     * @param  list<string>  $onlyIds
     * @return list<GateResult>
     */
    private function inspect(string $rootPath, array $onlyIds): array
    {
        $config = $this->manifestReader->read($rootPath);
        $context = new GateContext($rootPath, $config, new OwnersCatalogue($config));

        $results = [];
        foreach ($this->gates as $gate) {
            if ($onlyIds !== [] && ! in_array($gate->id(), $onlyIds, true)) {
                continue;
            }

            $started = hrtime(true);
            $result = $gate->run($context);
            $elapsedMs = (hrtime(true) - $started) / 1_000_000.0;
            $results[] = $result->withDuration($elapsedMs);
        }

        return $results;
    }
}
