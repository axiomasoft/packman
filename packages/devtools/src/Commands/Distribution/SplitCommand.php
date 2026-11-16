<?php

declare(strict_types=1);

namespace Packman\DevTools\Commands\Distribution;

use Override;
use Packman\Attributes\Api;
use Packman\DevTools\Internal\Console\OptionList;
use Packman\DevTools\Split\MonorepoSplitter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Orchestrates package subtree splits defined in split.json. */
#[Api(since: '1.0')]
#[AsCommand(name: 'split', description: 'Validate and run package subtree splits declared in split.json.')]
final class SplitCommand extends Command
{
    public function __construct(
        private readonly string $defaultRootPath,
        private readonly MonorepoSplitter $splitter = new MonorepoSplitter,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Plan subtree split commands without invoking git.')
            ->addOption('only', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Filter target package names or slugs.')
            ->addOption('branch', null, InputOption::VALUE_REQUIRED, 'Override target branch.')
            ->addOption('root', null, InputOption::VALUE_REQUIRED, 'Monorepo root directory.', $this->defaultRootPath);
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rawRoot = $input->getOption('root');
        $rootPath = is_string($rawRoot) && $rawRoot !== '' ? $rawRoot : $this->defaultRootPath;
        $dryRun = (bool) $input->getOption('dry-run');
        $rawBranch = $input->getOption('branch');
        $branch = is_string($rawBranch) && $rawBranch !== '' ? $rawBranch : null;

        $only = OptionList::parse($input->getOption('only'));

        $result = $this->splitter->split($rootPath, $dryRun, $only, $branch);

        foreach ($result['commands'] as $index => $cmd) {
            $target = $result['targets'][$index] ?? null;
            $label = $target !== null ? $target['name'] : "target#{$index}";
            $prefix = $dryRun ? '<comment>PLAN</comment>' : '<info>SPLIT</info>';
            $output->writeln(sprintf('%s [%s] %s', $prefix, $label, implode(' ', $cmd)));
        }

        foreach ($result['diagnostics'] as $diag) {
            $output->writeln("<error>FAIL</error> {$diag}");
        }

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
