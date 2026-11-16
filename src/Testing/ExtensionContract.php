<?php

declare(strict_types=1);

namespace Packman\Modules\Testing;

use Illuminate\Container\Container;
use InvalidArgumentException;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Modules\Data\CompiledRegistry;
use Packman\Modules\Data\ExtensionStamp;
use Packman\Modules\Data\InstalledPackageVersion;
use Packman\Modules\Data\ModuleManifest;
use Packman\Modules\Exceptions\CompilationException;
use Packman\Modules\Exceptions\RegistryFrozen;
use Packman\Modules\Internal\Compiler\RegistryCompiler;
use Throwable;

/**
 * Framework-neutral assertions for a contributed module snapshot.
 *
 * The result is deliberately independent of a test runner, so package authors
 * can use the same contract in PHPUnit, Pest or a standalone fixture gate.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final readonly class ExtensionContract
{
    /** @param list<string> $errors */
    private function __construct(
        private array $errors,
        public ?string $fingerprint,
    ) {}

    /**
     * @param  list<ModuleManifest>  $manifests
     * @param  array<string, InstalledPackageVersion>|list<string>  $installedPackages
     */
    public static function verify(array $manifests, array $installedPackages = []): self
    {
        try {
            [$names, $versions] = self::installedInput($installedPackages);
            // A static test entry point has no constructor to carry the compiler: it is taken from the running application.
            $compiler = Container::getInstance()->make(RegistryCompiler::class);
            $first = $compiler->compile(
                manifests: $manifests,
                installedPackages: $names,
                installedVersions: $versions,
            );
            $permuted = array_map(static function (ModuleManifest $manifest): ModuleManifest {
                $copy = clone $manifest;
                $copy->extenders = array_reverse($copy->extenders);
                $copy->replacements = array_reverse($copy->replacements);

                return $copy;
            }, array_reverse($manifests));
            $reversed = $compiler->compile(
                manifests: $permuted,
                installedPackages: $names,
                installedVersions: $versions,
            );
        } catch (Throwable $exception) {
            return new self(errors: [$exception::class.': '.$exception->getMessage()], fingerprint: null);
        }

        $errors = [];
        $snapshot = $first->toArray();

        if ($first->fingerprint !== $reversed->fingerprint || $snapshot !== $reversed->toArray()) {
            $errors[] = 'ModuleManifest permutation changed the compiled snapshot.';
        }

        $identities = [];
        foreach ($first->extensions as $extension) {
            assert(is_string($extension['point']) && is_string($extension['id']));
            $key = $extension['point']."\0".$extension['id'];

            if (isset($identities[$key])) {
                $errors[] = "Duplicate extension identity [{$extension['point']}:{$extension['id']}].";
            }
            $identities[$key] = true;
        }

        try {
            $roundTrip = CompiledRegistry::fromArray($snapshot);

            if ($roundTrip->fingerprint !== $first->fingerprint || $roundTrip->toArray() !== $snapshot) {
                $errors[] = 'Compiled artifact round-trip changed its bytes or fingerprint.';
            }
        } catch (Throwable $exception) {
            $errors[] = 'Compiled artifact round-trip failed: '.$exception->getMessage();
        }

        foreach (['schema' => 'unknown-registry-schema', 'fingerprint' => str_repeat('0', 64)] as $key => $value) {
            $corrupt = $snapshot;
            $corrupt[$key] = $value;

            try {
                CompiledRegistry::fromArray($corrupt);
                $errors[] = "Corrupt artifact {$key} was accepted.";
            } catch (CompilationException) {
                // Unknown schema and digest mismatch must fail closed.
            }
        }
        $truncated = $snapshot;
        unset($truncated['fingerprint']);

        try {
            CompiledRegistry::fromArray($truncated);
            $errors[] = 'Truncated artifact without fingerprint was accepted.';
        } catch (CompilationException) {
            // Missing identity is never a cache miss.
        }

        if ($first->entities !== []) {
            $definition = array_values($first->entities)[0];
            $collector = new ArrayEntityRegistry;
            $collector->register($definition);

            try {
                $collector->register($definition);
                $errors[] = 'Entity collector accepted duplicate registration.';
            } catch (RegistryFrozen) {
                // Expected before publication.
            }
            $collector->freeze();

            try {
                $collector->register($definition);
                $errors[] = 'Entity collector accepted registration after freeze.';
            } catch (RegistryFrozen) {
                // Expected after publication.
            }

            if ($collector->get($definition->handle) !== $definition || count($collector->all()) !== 1) {
                $errors[] = 'Rejected entity registration changed the collector.';
            }
        }

        if ($first->menu !== []) {
            $menu = $first->menu[0];
            $menu->label = 'contract-mutation-probe';

            if ($first->toArray() !== $snapshot) {
                $errors[] = 'Hydrated extender mutation changed authority bytes.';
            }
        }

        foreach ($manifests as $manifest) {
            $version = $versions[$manifest->composerName] ?? null;

            if ($version === null) {
                continue;
            }
            $changed = $versions;
            $changed[$manifest->composerName] = new InstalledPackageVersion(
                name: $version->name,
                version: $version->version.'.contract-probe',
                reference: $version->reference,
            );

            try {
                $newVersion = $compiler->compile(
                    manifests: $manifests,
                    installedPackages: $names,
                    installedVersions: $changed,
                );

                if ($newVersion->fingerprint === $first->fingerprint) {
                    $errors[] = "Version change for {$manifest->composerName} did not change the fingerprint.";
                }
            } catch (Throwable $exception) {
                $errors[] = "Version probe for {$manifest->composerName} failed: {$exception->getMessage()}";
            }
        }

        return new self(errors: $errors, fingerprint: $first->fingerprint);
    }

    public static function sameImplementation(ExtensionStamp $stored, ExtensionStamp $current): bool
    {
        return $stored->point === $current->point
            && $stored->id === $current->id
            && $stored->version === $current->version;
    }

    public function passed(): bool
    {
        return $this->errors === [];
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function summary(): string
    {
        return $this->passed()
            ? 'Extension contract passed ('.$this->fingerprint.').'
            : implode("\n", $this->errors);
    }

    /**
     * @param  array<string, InstalledPackageVersion>|list<string>  $installedPackages
     * @return array{list<string>, array<string, InstalledPackageVersion>}
     */
    private static function installedInput(array $installedPackages): array
    {
        if (array_is_list($installedPackages)) {
            foreach ($installedPackages as $name) {
                if (! is_string($name) || $name === '') {
                    throw new InvalidArgumentException('Installed package names must be non-empty strings.');
                }
            }

            return [$installedPackages, []];
        }

        foreach ($installedPackages as $name => $version) {
            if (! $version instanceof InstalledPackageVersion || $name !== $version->name) {
                throw new InvalidArgumentException('Installed package metadata must be keyed by its Composer name.');
            }
        }

        ksort($installedPackages);

        return [array_keys($installedPackages), $installedPackages];
    }
}
