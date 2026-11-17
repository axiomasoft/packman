<?php

declare(strict_types=1);

namespace Packman\DevTools\Gates\Contracts;

use Packman\Attributes\Api;
use Packman\DevTools\Contracts\Gates\QualityGate;
use Packman\DevTools\Data\Gates\GateContext;
use Packman\DevTools\Data\Gates\GateResult;
use Packman\DevTools\Data\Gates\PackageManifest;
use Packman\DevTools\Scanners\Errors\ErrorSourceScanner;

/**
 * Validates unique error codes, URN problem prefixes, and ProblemDefinitionProvider completeness.
 */
#[Api(since: '1.0')]
final readonly class ErrorRegistryGate implements QualityGate
{
    public function __construct(
        private ErrorSourceScanner $scanner = new ErrorSourceScanner,
    ) {}

    public function id(): string
    {
        return 'error-registry';
    }

    public function category(): string
    {
        return 'contracts';
    }

    public function description(): string
    {
        return 'Verifies ErrorCode wire codes, URN prefixes, and ProblemDefinitionProvider registrations.';
    }

    public function run(GateContext $context): GateResult
    {
        $targets = array_map(
            static fn (PackageManifest $package): array => ['name' => $package->name, 'path' => $package->path],
            $context->config->packages,
        );

        $report = $this->scanner->scanPackages($context->rootPath, $targets, $context->config->errorUrnPrefix());

        return $report->ok()
            ? GateResult::pass($this->id(), $this->category(), [sprintf('Declared %d valid ErrorCode definitions across packages.', count($report->entries))])
            : GateResult::fail($this->id(), $this->category(), $report->diagnostics);
    }
}
