<?php

declare(strict_types=1);

namespace Packman\DevTools\Gates\Architecture;

use Packman\Attributes\Api;
use Packman\DevTools\Contracts\Gates\QualityGate;
use Packman\DevTools\Data\Gates\GateContext;
use Packman\DevTools\Data\Gates\GateResult;
use Packman\DevTools\Data\Gates\PackageManifest;
use Packman\DevTools\Internal\Gates\GateFileWalker;

/**
 * Enforces strict package ownership and forbids cross-package imports of \Internal\ symbols.
 */
#[Api(since: '1.0')]
final readonly class OwnershipGate implements QualityGate
{
    private GateFileWalker $walker;

    public function __construct()
    {
        $this->walker = new GateFileWalker;
    }

    public function id(): string
    {
        return 'ownership';
    }

    public function category(): string
    {
        return 'architecture';
    }

    public function description(): string
    {
        return 'Forbids cross-package imports of Internal\\ classes and verifies every package owns an unambiguous namespace prefix.';
    }

    public function run(GateContext $context): GateResult
    {
        $violations = [];
        $seenNamespaces = [];

        foreach ($context->config->packages as $package) {
            $ns = $package->normalizedNamespace();

            if ($ns === '\\') {
                continue;
            }

            if (isset($seenNamespaces[$ns])) {
                $violations[] = "Duplicate package namespace prefix [{$ns}] between {$seenNamespaces[$ns]} and {$package->name}.";
            }

            $seenNamespaces[$ns] = $package->name;
        }

        if ($context->config->disallowInternalCrossImports()) {
            foreach ($context->config->packages as $package) {
                $srcDir = $context->packageDirectory($package).'/src';
                foreach ($this->walker->phpFiles($srcDir) as $file) {
                    $rel = $this->walker->relativePath($context->rootPath, $file);
                    foreach ($this->walker->importedSymbols($file) as $fqcn) {
                        $owner = $context->owners->ownerOfClass($fqcn);

                        if (! $owner instanceof PackageManifest || $owner->name === $package->name) {
                            continue;
                        }

                        if (str_contains('\\'.$fqcn, '\\Internal\\')) {
                            $violations[] = "[{$package->name}] {$rel} imports internal symbol [{$fqcn}] owned by [{$owner->name}].";
                        }
                    }
                }
            }
        }

        return $violations === []
            ? GateResult::pass($this->id(), $this->category(), ['Zero cross-package internal symbol imports detected.'])
            : GateResult::fail($this->id(), $this->category(), $violations);
    }
}
