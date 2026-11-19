<?php

declare(strict_types=1);

namespace Packman\DevTools\Commands\Make;

use InvalidArgumentException;
use Override;
use Packman\Attributes\Api;
use Packman\DevTools\Scaffold\Layout;
use Packman\DevTools\Scaffold\Place;
use Packman\DevTools\Scaffold\ScaffoldCollisionException;
use Packman\DevTools\Scaffold\ScaffoldRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Scaffolds a new package from one of the 4 Packman architecture profiles using StubStore. */
#[Api(since: '1.0')]
#[AsCommand(name: 'make', description: 'Scaffold a package using profile stubs (php-library, laravel-package, packman-plugin, packman-module).')]
final class MakeCommand extends Command
{
    public function __construct(
        private readonly string $defaultRootPath,
        private readonly Layout $layout = new Layout,
        private readonly ScaffoldRunner $runner = new ScaffoldRunner,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->addArgument('profile', InputArgument::REQUIRED, 'Profile name: '.implode(', ', Layout::PROFILES))
            ->addArgument('name', InputArgument::REQUIRED, 'Package name or vendor/package slug.')
            ->addOption('vendor', null, InputOption::VALUE_REQUIRED, 'Override vendor segment.')
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Base output directory relative to root.', 'packages')
            ->addOption('minimal', null, InputOption::VALUE_NONE, 'Create a module or plugin with only its registration contract.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite existing files.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print planned file paths without writing to disk.')
            ->addOption('root', null, InputOption::VALUE_REQUIRED, 'Target workspace root.', $this->defaultRootPath);
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rawProfile = $input->getArgument('profile');
        $rawName = $input->getArgument('name');
        $rawVendor = $input->getOption('vendor');
        $rawDir = $input->getOption('dir');
        $rawRoot = $input->getOption('root');

        $profile = is_string($rawProfile) ? trim($rawProfile) : '';
        $name = is_string($rawName) ? trim($rawName) : '';
        $vendor = is_string($rawVendor) && $rawVendor !== '' ? $rawVendor : null;
        $dir = is_string($rawDir) ? $rawDir : 'packages';
        $rootPath = rtrim(is_string($rawRoot) && $rawRoot !== '' ? $rawRoot : $this->defaultRootPath, '/');
        $force = (bool) $input->getOption('force');
        $dryRun = (bool) $input->getOption('dry-run');

        if ($name === '') {
            $output->writeln('<error>Package name cannot be empty.</error>');

            return self::FAILURE;
        }

        try {
            $place = Place::fromInput($name, $vendor, $dir);
            $files = $this->layout->filesFor($profile, $place, $force, (bool) $input->getOption('minimal'));
        } catch (InvalidArgumentException $exception) {
            $output->writeln("<error>{$exception->getMessage()}</error>");

            return self::FAILURE;
        }

        if ($dryRun) {
            foreach ($files as $file) {
                $output->writeln("<comment>PLAN</comment> {$file->relativePath}");
            }
        } else {
            try {
                $written = $this->runner->run($rootPath, static fn (): array => $files);
            } catch (ScaffoldCollisionException $exception) {
                $first = $exception->existingPaths[0] ?? $exception->getMessage();
                $output->writeln("<error>Target file already exists (pass --force to overwrite): {$first}</error>");

                return self::FAILURE;
            }

            foreach ($written as $entry) {
                $output->writeln("<info>CREATE</info> {$entry['relativePath']}");
            }
        }

        $output->writeln(sprintf(
            '<info>Scaffold [%s] for %s (%d files).</info>',
            $profile,
            $place->composerName,
            count($files),
        ));

        return self::SUCCESS;
    }
}
