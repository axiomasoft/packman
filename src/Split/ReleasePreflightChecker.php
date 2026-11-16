<?php

declare(strict_types=1);

namespace Packman\DevTools\Split;

use Packman\Attributes\Api;
use Packman\DevTools\Gates\Runner\GateRunner;

/**
 * Aggregated preflight check combining QualityGates, compatibility matrix checks, and dry-run split verification.
 */
#[Api(since: '1.0')]
final readonly class ReleasePreflightChecker
{
    public function __construct(
        private GateRunner $gateRunner,
        private CompatibilityMatrixChecker $compatibility = new CompatibilityMatrixChecker,
        private MonorepoSplitter $splitter = new MonorepoSplitter,
    ) {}

    /** @return array{ok: bool, diagnostics: list<string>} */
    public function verify(string $rootPath): array
    {
        $diagnostics = [];

        foreach ($this->gateRunner->run($rootPath) as $result) {
            if (! $result->passed) {
                foreach ($result->violations as $violation) {
                    $diagnostics[] = "[gate:{$result->gateId}] {$violation}";
                }
            }
        }

        $compat = $this->compatibility->check($rootPath);
        foreach ($compat['diagnostics'] as $diag) {
            $diagnostics[] = "[compat] {$diag}";
        }

        $split = $this->splitter->split($rootPath, dryRun: true);
        foreach ($split['diagnostics'] as $diag) {
            $diagnostics[] = "[split] {$diag}";
        }

        return [
            'ok' => $diagnostics === [],
            'diagnostics' => $diagnostics,
        ];
    }
}
