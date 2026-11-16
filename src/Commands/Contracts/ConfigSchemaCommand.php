<?php

declare(strict_types=1);

namespace Packman\DevTools\Commands\Contracts;

use Override;
use Packman\Attributes\Api;
use Packman\DevTools\Gates\Runner\PackmanManifestReader;
use Packman\DevTools\Generators\Config\ConfigSchemaGenerator;
use Symfony\Component\Console\Attribute\AsCommand;

/** Generates or verifies Draft 2020-12 JSON Schema configuration contracts per package. */
#[Api(since: '1.0')]
#[AsCommand(name: 'config-schema', description: 'Check or generate package JSON Schema configuration manifests.')]
final class ConfigSchemaCommand extends AbstractSyncCommand
{
    public function __construct(
        string $defaultRootPath,
        private readonly ConfigSchemaGenerator $generator = new ConfigSchemaGenerator,
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
