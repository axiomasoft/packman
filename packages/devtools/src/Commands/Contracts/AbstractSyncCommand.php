<?php

declare(strict_types=1);

namespace Packman\DevTools\Commands\Contracts;

use Override;
use Packman\Attributes\Api;
use Packman\DevTools\Data\Gates\PackageManifest;
use Packman\DevTools\Gates\Runner\PackmanManifestReader;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Shared base command for --check / --write package contract generators. */
#[Api(since: '1.0')]
abstract class AbstractSyncCommand extends Command
{
    public function __construct(
        private readonly string $defaultRootPath,
        private readonly PackmanManifestReader $manifestReader = new PackmanManifestReader,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->addOption('check', null, InputOption::VALUE_NONE, 'Verify manifests without writing files (default).')
            ->addOption('write', null, InputOption::VALUE_NONE, 'Write updated manifests to disk.')
            ->addOption('root', null, InputOption::VALUE_REQUIRED, 'Monorepo root directory.', $this->defaultRootPath);
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rawRoot = $input->getOption('root');
        $rootPath = is_string($rawRoot) && $rawRoot !== '' ? $rawRoot : $this->defaultRootPath;
        $write = (bool) $input->getOption('write');

        $config = $this->manifestReader->read($rootPath);
        $packages = array_map(
            static fn (PackageManifest $pkg): array => ['name' => $pkg->name, 'path' => $pkg->path],
            $config->packages,
        );

        $result = $this->runSync($rootPath, $packages, $config->errorUrnPrefix(), $write);

        foreach ($result['changed'] as $path) {
            $output->writeln("<info>WROTE</info> {$path}");
        }

        foreach ($result['diagnostics'] as $diagnostic) {
            $output->writeln("<error>FAIL</error> {$diagnostic}");
        }

        if ($result['ok']) {
            $mode = $write ? 'synchronized' : 'verified';
            $output->writeln(sprintf('<info>%s %s cleanly (%d packages).</info>', $this->getName() ?? 'contract', $mode, count($packages)));

            return self::SUCCESS;
        }

        return self::FAILURE;
    }

    /**
     * @param  list<array{name: string, path: string}>  $packages
     * @return array{ok: bool, diagnostics: list<string>, changed: list<string>}
     */
    abstract protected function runSync(string $rootPath, array $packages, string $urnPrefix, bool $write): array;
}
