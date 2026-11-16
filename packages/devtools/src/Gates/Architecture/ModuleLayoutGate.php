<?php

declare(strict_types=1);

namespace Packman\DevTools\Gates\Architecture;

use Packman\Attributes\Api;
use Packman\DevTools\Contracts\Gates\QualityGate;
use Packman\DevTools\Data\Gates\GateContext;
use Packman\DevTools\Data\Gates\GateResult;

/**
 * Enforces profile-driven source directory structure and composer dependency constraints per package.
 */
#[Api(since: '1.0')]
final class ModuleLayoutGate implements QualityGate
{
    public function id(): string
    {
        return 'module-layout';
    }

    public function category(): string
    {
        return 'architecture';
    }

    public function description(): string
    {
        return 'Verifies package src/ directories and composer dependencies conform to schemas/profiles/*.profile.json.';
    }

    public function run(GateContext $context): GateResult
    {
        $violations = [];

        foreach ($context->config->packages as $package) {
            $profile = $context->config->profileConfig($package->profile);

            if ($profile === null) {
                $violations[] = "[{$package->name}] Unknown profile [{$package->profile}] in packman.json.";

                continue;
            }

            $srcDir = $context->packageDirectory($package).'/src';

            if (! is_dir($srcDir)) {
                $violations[] = "[{$package->name}] Missing src/ directory at {$package->path}/src.";

                continue;
            }

            $allowedDirs = is_array($profile['allowed_directories'] ?? null)
                ? array_values(array_filter($profile['allowed_directories'], is_string(...)))
                : [];

            $entries = scandir($srcDir);

            if (is_array($entries)) {
                foreach ($entries as $entry) {
                    if ($entry === '.' || $entry === '..') {
                        continue;
                    }

                    $full = $srcDir.'/'.$entry;

                    if (is_dir($full) && $allowedDirs !== [] && ! in_array($entry, $allowedDirs, true)) {
                        $violations[] = sprintf(
                            '[%s] Directory %s/src/%s is not permitted under profile [%s].',
                            $package->name,
                            $package->path,
                            $entry,
                            $package->profile,
                        );
                    }
                }
            }

            $composer = $context->readComposerJson($package);
            $expectedType = is_string($profile['composer_type'] ?? null) ? $profile['composer_type'] : 'library';
            $actualType = is_string($composer['type'] ?? null) ? $composer['type'] : '';

            if ($actualType !== $expectedType) {
                $violations[] = "[{$package->name}] composer.json type '{$actualType}' does not match profile '{$package->profile}' requirement '{$expectedType}'.";
            }

            $allowLaravel = (bool) ($profile['allow_laravel_required'] ?? true);

            if (! $allowLaravel && is_array($composer['require'] ?? null)) {
                foreach (array_keys($composer['require']) as $dep) {
                    $depName = (string) $dep;

                    if ($depName === 'laravel/framework' || str_starts_with($depName, 'illuminate/')) {
                        $violations[] = "[{$package->name}] Profile [{$package->profile}] forbids requiring [{$depName}] in composer.json require.";
                    }
                }
            }
        }

        return $violations === []
            ? GateResult::pass($this->id(), $this->category(), [sprintf('Validated layout across %d packages.', count($context->config->packages))])
            : GateResult::fail($this->id(), $this->category(), $violations);
    }
}
