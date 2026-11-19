<?php

declare(strict_types=1);

namespace Packman\DevTools\Gates\Architecture;

use Composer\Autoload\ClassLoader;
use Packman\Attributes\Api;
use Packman\DevTools\Contracts\Gates\QualityGate;
use Packman\DevTools\Data\Gates\GateContext;
use Packman\DevTools\Data\Gates\GateResult;
use Packman\DevTools\Data\Gates\PackageManifest;
use Packman\DevTools\Internal\Gates\GateFileWalker;

/**
 * Enforces strict package ownership and forbids cross-package imports of internal symbols.
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
        return 'Forbids cross-package imports of namespace-internal or #[Internal] types and verifies every package owns an unambiguous namespace prefix.';
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

        $internalOwners = $this->internalOwners($context, $violations);

        if ($context->config->disallowInternalCrossImports()) {
            /** @var array<string, list<string>> $internalByFile */
            $internalByFile = [];
            foreach ($context->config->packages as $package) {
                $srcDir = $context->packageDirectory($package).'/src';
                foreach ($this->walker->phpFiles($srcDir) as $file) {
                    $rel = $this->walker->relativePath($context->rootPath, $file);
                    foreach ($this->walker->importedSymbols($file) as $fqcn) {
                        $owner = $context->owners->ownerOfClass($fqcn);

                        if ($owner instanceof PackageManifest && (
                            $owner->name === $package->name
                            || (isset($internalOwners[$owner->name], $internalOwners[$package->name])
                                && $internalOwners[$owner->name] === $internalOwners[$package->name])
                        )) {
                            continue;
                        }

                        $internal = str_contains('\\'.$fqcn, '\\Internal\\');
                        $source = $this->sourceFile($fqcn, $owner, $context);

                        if (! $internal && $source !== null) {
                            $internalByFile[$source] ??= $this->walker->internalSymbols($source);
                            $internal = in_array($fqcn, $internalByFile[$source], true);
                        }

                        if ($internal) {
                            $ownerName = $owner->name ?? 'external dependency';
                            $violations[] = "[{$package->name}] {$rel} imports internal symbol [{$fqcn}] owned by [{$ownerName}].";
                        }
                    }
                }
            }
        }

        return $violations === []
            ? GateResult::pass($this->id(), $this->category(), ['Zero cross-package internal symbol imports detected.'])
            : GateResult::fail($this->id(), $this->category(), $violations);
    }

    /**
     * @param  list<string>  $violations
     * @return array<string, string> local runtime package => declared product owner
     */
    private function internalOwners(GateContext $context, array &$violations): array
    {
        $groups = array_key_exists('internal_ownership_groups', $context->config->gates)
            ? $context->config->gates['internal_ownership_groups']
            : [];

        if (! is_array($groups) || ($groups !== [] && array_is_list($groups))) {
            $violations[] = 'gates.internal_ownership_groups must map product owner names to lists of local runtime packages.';

            return [];
        }

        $owners = [];

        foreach ($groups as $group => $members) {
            if (! is_string($group) || trim($group) === '' || ! is_array($members) || ! array_is_list($members) || count($members) < 2) {
                $violations[] = 'Every internal ownership group needs a non-empty product owner name and at least two local runtime package names.';

                continue;
            }

            foreach ($members as $member) {
                if (! is_string($member)) {
                    $violations[] = "Internal ownership group [{$group}] contains a non-string package name.";

                    continue;
                }

                $package = $context->config->findPackageByName($member);

                if ($package === null || ! $this->isLocalRuntimePackage($package, $context)) {
                    $violations[] = "Internal ownership group [{$group}] cannot include unknown, external, dev-only or non-runtime package [{$member}].";

                    continue;
                }

                if (isset($owners[$member])) {
                    $violations[] = "Package [{$member}] occurs more than once in internal ownership groups [{$owners[$member]}] and [{$group}].";

                    continue;
                }

                $owners[$member] = $group;
            }
        }

        return $owners;
    }

    private function isLocalRuntimePackage(PackageManifest $package, GateContext $context): bool
    {
        if ($package->devOnly || in_array($package->type, ['dev', 'tooling', 'metapackage'], true) || $package->profile === 'metapackage') {
            return false;
        }

        $root = realpath($context->rootPath);
        $source = realpath($context->packageDirectory($package));

        if ($root === false || $source === false || ($source !== $root && ! str_starts_with($source, $root.'/'))) {
            return false;
        }

        foreach ([$root.'/vendor', ...array_keys(ClassLoader::getRegisteredLoaders())] as $vendor) {
            $vendor = realpath($vendor);

            if ($vendor !== false && ($source === $vendor || str_starts_with($source, $vendor.'/'))) {
                return false;
            }
        }

        return true;
    }

    private function sourceFile(string $fqcn, ?PackageManifest $owner, GateContext $context): ?string
    {
        if ($owner !== null) {
            $composer = $context->readComposerJson($owner);
            $autoload = $composer['autoload'] ?? null;
            $prefixes = is_array($autoload) ? ($autoload['psr-4'] ?? null) : null;

            if (is_array($prefixes)) {
                foreach ($prefixes as $prefix => $paths) {
                    if (! is_string($prefix) || ! str_starts_with($fqcn, $prefix)) {
                        continue;
                    }

                    foreach (is_array($paths) ? $paths : [$paths] as $path) {
                        if (! is_string($path)) {
                            continue;
                        }

                        $file = $context->packageDirectory($owner).'/'.trim($path, '/').'/'.str_replace('\\', '/', substr($fqcn, strlen($prefix))).'.php';

                        if (is_file($file)) {
                            return $file;
                        }
                    }
                }
            }
        }

        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            $file = $loader->findFile($fqcn);

            if (is_string($file)) {
                return $file;
            }
        }

        return null;
    }
}
