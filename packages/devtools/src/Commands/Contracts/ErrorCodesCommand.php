<?php

declare(strict_types=1);

namespace Packman\DevTools\Commands\Contracts;

use Override;
use Packman\Attributes\Api;
use Packman\DevTools\Gates\Runner\PackmanManifestReader;
use Packman\DevTools\Generators\Errors\ErrorCodeSchemaGenerator;
use Symfony\Component\Console\Attribute\AsCommand;

/** Scans package ErrorCode enums and ProblemDefinitionProviders to check or write error-codes.json. */
#[Api(since: '1.0')]
#[AsCommand(name: 'error-codes', description: 'Check or generate package error code contract manifests.')]
final class ErrorCodesCommand extends AbstractSyncCommand
{
    public function __construct(
        string $defaultRootPath,
        private readonly ErrorCodeSchemaGenerator $generator = new ErrorCodeSchemaGenerator,
        PackmanManifestReader $manifestReader = new PackmanManifestReader,
    ) {
        parent::__construct($defaultRootPath, $manifestReader);
    }

    #[Override]
    protected function runSync(string $rootPath, array $packages, string $urnPrefix, bool $write): array
    {
        return $this->generator->sync($rootPath, $packages, $urnPrefix, $write);
    }
}
