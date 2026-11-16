<?php

declare(strict_types=1);

namespace Packman\DevTools\Gates\Architecture;

use Packman\Attributes\Api;
use Packman\DevTools\Contracts\Gates\QualityGate;
use Packman\DevTools\Data\Gates\GateContext;
use Packman\DevTools\Data\Gates\GateResult;
use Packman\DevTools\Scanners\Surface\SurfaceScanner;

/**
 * Verifies every type declaration in src/ classifies cleanly into #[Api], #[Spi], or #[Internal] without leakage.
 */
#[Api(since: '1.0')]
final readonly class AttributesArchitectureGate implements QualityGate
{
    public function __construct(
        private SurfaceScanner $scanner = new SurfaceScanner,
    ) {}

    public function id(): string
    {
        return 'attributes-architecture';
    }

    public function category(): string
    {
        return 'architecture';
    }

    public function description(): string
    {
        return 'Verifies explicit #[Api]/#[Spi]/#[Internal] annotations with zero internal signature leaks.';
    }

    public function run(GateContext $context): GateResult
    {
        $violations = [];
        $symbolCount = 0;

        foreach ($context->config->packages as $package) {
            $srcDir = $context->packageDirectory($package).'/src';
            $report = $this->scanner->scanPackage($package->name, $srcDir);
            $symbolCount += count($report->symbols);

            foreach ($report->diagnostics as $diagnostic) {
                $violations[] = "[{$package->name}] ".$diagnostic->format();
            }
        }

        return $violations === []
            ? GateResult::pass($this->id(), $this->category(), [sprintf('Scanned %d public/SPI symbols with zero violations.', $symbolCount)])
            : GateResult::fail($this->id(), $this->category(), $violations);
    }
}
