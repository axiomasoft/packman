<?php

declare(strict_types=1);

namespace Packman\DevTools\Commands\Gates;

use Override;
use Packman\Attributes\Api;
use Packman\DevTools\Gates\Runner\GateRunner;
use Packman\DevTools\Internal\Console\OptionList;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Executes all 16 Packman quality gates (or a filtered subset via --only) against packman.json. */
#[Api(since: '1.0')]
#[AsCommand(name: 'gate', description: 'Run all architecture, boundary, contract, and quality gates.')]
final class GateRunnerCommand extends Command
{
    public function __construct(
        private readonly string $defaultRootPath,
        private readonly ?GateRunner $runner = null,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->addOption('only', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Filter gate IDs to execute.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit JSON report.')
            ->addOption('root', null, InputOption::VALUE_REQUIRED, 'Monorepo root directory.', $this->defaultRootPath);
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rawRoot = $input->getOption('root');
        $rootPath = is_string($rawRoot) && $rawRoot !== '' ? $rawRoot : $this->defaultRootPath;

        $onlyIds = OptionList::parse($input->getOption('only'));

        $runner = $this->runner ?? GateRunner::default();
        $results = $runner->run($rootPath, $onlyIds);

        if ($results === []) {
            $output->writeln('<error>No matching quality gates executed.</error>');

            return self::FAILURE;
        }

        $failed = 0;
        foreach ($results as $result) {
            if (! $result->passed) {
                $failed++;
            }
        }

        if ((bool) $input->getOption('json')) {
            $payload = array_map(static fn ($res): array => [
                'id' => $res->gateId,
                'category' => $res->category,
                'passed' => $res->passed,
                'duration_ms' => round($res->durationMs, 2),
                'violations' => $res->violations,
                'notes' => $res->notes,
            ], $results);
            $output->writeln(json_encode([
                'ok' => $failed === 0,
                'gates' => $payload,
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return $failed === 0 ? self::SUCCESS : self::FAILURE;
        }

        foreach ($results as $result) {
            $status = $result->passed ? '<info>PASS</info>' : '<error>FAIL</error>';
            $output->writeln(sprintf('%s [%s] %s (%.1f ms)', $status, $result->category, $result->gateId, $result->durationMs));
            foreach ($result->violations as $violation) {
                $output->writeln("  - {$violation}");
            }
        }

        $output->writeln(sprintf(
            '%s: %d/%d quality gates passed.',
            $failed === 0 ? '<info>OK</info>' : '<error>FAILED</error>',
            count($results) - $failed,
            count($results),
        ));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
