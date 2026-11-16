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
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Plan synchronized files without writing to disk.')
            ->addOption('root', null, InputOption::VALUE_REQUIRED, 'Repository root directory.', $this->defaultRootPath);
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rawRoot = $input->getOption('root');
        $rootPath = is_string($rawRoot) && $rawRoot !== '' ? $rawRoot : $this->defaultRootPath;
        $dryRun = (bool) $input->getOption('dry-run');

        $result = $this->synchronizer->sync($rootPath, $dryRun);

        foreach ($result['written'] as $file) {
            $output->writeln("<info>SYNC</info> {$file}");
        }

        if ($dryRun) {
            foreach ($result['planned'] as $file) {
                $output->writeln("<comment>PLAN</comment> {$file}");
            }
        }

        foreach ($result['preserved'] as $file) {
            $output->writeln("<comment>KEEP</comment> {$file}");
        }

        foreach ($result['notes'] as $note) {
            $output->writeln("<info>{$note}</info>");
        }

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
