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
        string $defaultRootPath,
        private readonly ApiManifestGenerator $generator = new ApiManifestGenerator,
        PackmanManifestReader $manifestReader = new PackmanManifestReader,
    ) {
        parent::__construct($defaultRootPath, $manifestReader);
    }

    #[Override]
    protected function runSync(string $rootPath, array $packages, string $urnPrefix, bool $write): array
    {
        return $this->generator->sync($rootPath, $packages, $write);
    }
}
