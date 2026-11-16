<?php

declare(strict_types=1);

namespace Packman\DevTools\Commands\Sync;

use Override;
use Packman\Attributes\Api;
use Packman\DevTools\Sync\EcosystemAiSynchronizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Synchronizes canonical ai/ templates into .claude/, .codex/agents/*.toml, .gemini/, and AGENTS.md. */
#[Api(since: '1.0')]
#[AsCommand(name: 'ai:sync', description: 'Synchronize canonical ai/ rules and agent packs to Claude, Codex, and Gemini.')]
final class AiSyncCommand extends Command
{
    public function __construct(
        private readonly string $defaultRootPath,
        private readonly EcosystemAiSynchronizer $synchronizer = new EcosystemAiSynchronizer,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Verify generated AI artifacts match canonical ai/ sources without writing to disk.')
            ->addOption('check', null, InputOption::VALUE_NONE, 'Alias for --dry-run drift verification.')
            ->addOption('root', null, InputOption::VALUE_REQUIRED, 'Repository root directory.', $this->defaultRootPath);
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rawRoot = $input->getOption('root');
        $rootPath = is_string($rawRoot) && $rawRoot !== '' ? $rawRoot : $this->defaultRootPath;
        $dryRun = (bool) $input->getOption('dry-run') || (bool) $input->getOption('check');

        $result = $this->synchronizer->sync($rootPath, $dryRun);

        foreach ($result['written'] as $file) {
            $output->writeln("<info>SYNC</info> {$file}");
        }

        foreach ($result['pruned'] as $file) {
            $output->writeln("<comment>PRUNE</comment> {$file}");
        }

        if ($dryRun) {
            foreach ($result['planned'] as $file) {
                $output->writeln("<comment>PLAN</comment> {$file}");
            }

            foreach ($result['missing'] as $file) {
                $output->writeln("<error>MISSING</error> {$file}");
            }

            foreach ($result['drifted'] as $file) {
                $output->writeln("<error>DRIFT</error> {$file}");
            }

            foreach ($result['orphans'] as $file) {
                $output->writeln("<error>ORPHAN</error> {$file}");
            }
        }

        foreach ($result['preserved'] as $file) {
            $output->writeln("<comment>KEEP</comment> {$file}");
        }

        $noteTag = $result['ok'] ? 'info' : 'error';

        foreach ($result['notes'] as $note) {
            $output->writeln("<{$noteTag}>{$note}</{$noteTag}>");
        }

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
