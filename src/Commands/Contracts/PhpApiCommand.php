<?php

declare(strict_types=1);

namespace Packman\DevTools\Commands\Contracts;

use Override;
use Packman\Attributes\Api;
use Packman\DevTools\Gates\Runner\PackmanManifestReader;
use Packman\DevTools\Generators\Contracts\ApiManifestGenerator;
use Symfony\Component\Console\Attribute\AsCommand;

/** Scans package #[Api] / #[Spi] types and checks or writes resources/contracts/php-api.json. */
#[Api(since: '1.0')]
#[AsCommand(name: 'php-api', description: 'Check or generate package resources/contracts/php-api.json manifests.')]
final class PhpApiCommand extends AbstractSyncCommand
{
    public function __construct(
        private readonly string $rootPathDefault,
        private readonly ApiManifestGenerator $generator = new ApiManifestGenerator,
        private readonly PackmanManifestReader $reader = new PackmanManifestReader,
    ) {
        parent::__construct($rootPathDefault, $reader);
    }

    #[Override]
    protected function runSync(string $rootPath, array $packages, string $urnPrefix, bool $write): array
    {
        $config = $this->reader->read($rootPath);
        $globalRequire = (bool) ($config->gates['require_api_manifest'] ?? true);
        $filtered = [];

        foreach ($config->packages as $package) {
            $profile = $config->profileConfig($package->profile);
            $requireManifest = $profile !== null && array_key_exists('require_php_api_manifest', $profile)
                ? (bool) $profile['require_php_api_manifest']
                : $globalRequire;

            if ($requireManifest) {
                $filtered[] = ['name' => $package->name, 'path' => $package->path];
            }
        }

        return $this->generator->sync($rootPath, $filtered, $write);
    }
}
