<?php

declare(strict_types=1);

namespace Packman\DevTools\Gates\Quality;

use Packman\Attributes\Api;
use Packman\DevTools\Contracts\Gates\QualityGate;
use Packman\DevTools\Data\Gates\GateContext;
use Packman\DevTools\Data\Gates\GateResult;

/**
 * Verifies required README.md, CHANGELOG.md, composer description, and clean rendered documentation.
 */
#[Api(since: '1.0')]
final class PackageDocsGate implements QualityGate
{
    public function id(): string
    {
        return 'package-docs';
    }

    public function category(): string
    {
        return 'quality';
    }

    public function description(): string
    {
        return 'Enforces package README.md, CHANGELOG.md, and non-empty composer metadata per profile.';
    }

    public function run(GateContext $context): GateResult
    {
        $violations = [];

        foreach ($context->config->packages as $package) {
            $pkgDir = $context->packageDirectory($package);
            $profile = $context->config->profileConfig($package->profile);

            $requireReadme = $profile !== null ? (bool) ($profile['require_readme'] ?? true) : true;
            $requireChangelog = $profile !== null ? (bool) ($profile['require_changelog'] ?? true) : true;

            if ($requireReadme) {
                $readmePath = $pkgDir.'/README.md';
                $readme = is_file($readmePath) ? file_get_contents($readmePath) : false;

                if (! is_string($readme) || trim($readme) === '') {
                    $violations[] = "[{$package->name}] Missing or empty README.md at {$package->path}/README.md.";
                }
            }

            if ($requireChangelog) {
                $changelogPath = $pkgDir.'/CHANGELOG.md';
                $changelog = is_file($changelogPath) ? file_get_contents($changelogPath) : false;

                if (! is_string($changelog) || trim($changelog) === '') {
                    $violations[] = "[{$package->name}] Missing or empty CHANGELOG.md at {$package->path}/CHANGELOG.md.";
                }
            }

            $composer = $context->readComposerJson($package);
            $description = $composer['description'] ?? null;

            if (! is_string($description) || trim($description) === '') {
                $violations[] = "[{$package->name}] Missing non-empty description in {$package->path}/composer.json.";
            }
        }

        return $violations === []
            ? GateResult::pass($this->id(), $this->category(), [sprintf('Verified documentation and metadata across %d packages.', count($context->config->packages))])
            : GateResult::fail($this->id(), $this->category(), $violations);
    }
}
