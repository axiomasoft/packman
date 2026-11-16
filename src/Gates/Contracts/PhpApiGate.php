<?php

declare(strict_types=1);

namespace Packman\DevTools\Gates\Contracts;

use Packman\Attributes\Api;
use Packman\DevTools\Contracts\Gates\QualityGate;
use Packman\DevTools\Data\Gates\GateContext;
use Packman\DevTools\Data\Gates\GateResult;
use Packman\DevTools\Generators\Contracts\ApiManifestGenerator;

/**
 * Verifies resources/contracts/php-api.json exists, matches schemas/php-api.schema.json, and is up to date.
 */
#[Api(since: '1.0')]
final readonly class PhpApiGate implements QualityGate
{
    public function __construct(
        private ApiManifestGenerator $generator = new ApiManifestGenerator,
    ) {}

    public function id(): string
    {
        return 'php-api';
    }

    public function category(): string
    {
        return 'contracts';
    }

    public function description(): string
    {
        return 'Verifies every package requiring an API manifest has a fresh, schema-valid php-api.json.';
    }

    public function run(GateContext $context): GateResult
    {
        $targets = [];
        $globalRequire = (bool) ($context->config->gates['require_api_manifest'] ?? true);

        foreach ($context->config->packages as $package) {
            $profile = $context->config->profileConfig($package->profile);
            $requireManifest = $profile !== null && array_key_exists('require_php_api_manifest', $profile)
                ? (bool) $profile['require_php_api_manifest']
                : $globalRequire;

            if (! $requireManifest) {
                continue;
            }

            $targets[] = ['name' => $package->name, 'path' => $package->path];
        }

        $sync = $this->generator->sync($context->rootPath, $targets, write: false);

        return $sync['ok']
            ? GateResult::pass($this->id(), $this->category(), [sprintf('Verified php-api.json manifests for %d packages.', count($targets))])
            : GateResult::fail($this->id(), $this->category(), $sync['diagnostics']);
    }
}
