<?php

declare(strict_types=1);

namespace Packman\Doctor\Laravel\Console;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use Packman\Attributes\Internal;
use Packman\Doctor\Contracts\DoctorRegistryContract;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Data\DoctorReport;
use Packman\Doctor\Enums\CheckStatus;
use Packman\Doctor\Internal\DoctorReportBuilder;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

#[Internal]
final class DoctorCommand extends Command
{
    protected $signature = 'packman:doctor
        {--production : Check against the production profile: warnings may become errors}
        {--json : Print one packman.doctor/v1 JSON document}
        {--prefix=* : Run only checks matching this dotted prefix}
        {--check=* : Alias for --prefix}
        {--sample=1000 : Row limit of sampling checks}
        {--probe-storage : Allow probing storage disks}';

    protected $description = 'Diagnose the Packman host setup (read-only)';

    public function handle(DoctorReportBuilder $builder, DoctorRegistryContract $registry): int
    {
        $sample = filter_var($this->option('sample'), FILTER_VALIDATE_INT);

        if ($sample === false || $sample < 1) {
            return $this->usage('--sample must be a positive integer.');
        }

        $rawPrefixes = array_merge(
            $this->option('prefix'),
            $this->option('check'),
        );

        $prefixes = [];
        foreach ($rawPrefixes as $value) {
            if (is_string($value) && $value !== '') {
                $prefixes[] = $value;
            }
        }

        $unknown = $registry->unmatched($prefixes);

        if ($unknown !== []) {
            return $this->usage('No doctor check matches --prefix='.implode(', ', $unknown).'.');
        }

        $report = $builder->build(
            context: new DoctorContext(
                production: $this->option('production') === true,
                probeStorage: $this->option('probe-storage') === true,
                sampleLimit: $sample,
                now: new DateTimeImmutable('now', new DateTimeZone('UTC')),
            ),
            prefixes: $prefixes,
            exposeMessages: $this->output->isVerbose(),
        );

        if ($this->option('json') === true) {
            $this->output->writeln(
                json_encode($report->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                OutputInterface::OUTPUT_RAW,
            );
        } else {
            $this->render($report);
        }

        return $report->exitCode();
    }

    private function usage(string $message): int
    {
        $this->output->getErrorStyle()->writeln('<error>'.OutputFormatter::escape($message).'</error>');

        return 2;
    }

    private function render(DoctorReport $report): void
    {
        foreach ($report->checks as $check) {
            $this->line('<options=bold>'.OutputFormatter::escape($check->title).'</> <fg=gray>'.$check->key.'</>');

            if ($check->detail !== null) {
                $this->line('  <fg=gray>detail: '.OutputFormatter::escape($check->detail).'</>');
            }

            foreach ($check->findings as $finding) {
                [$mark, $color] = match ($finding->status) {
                    CheckStatus::Ok => ['ok  ', 'green'],
                    CheckStatus::Warning => ['warn', 'yellow'],
                    CheckStatus::Error => ['FAIL', 'red'],
                    CheckStatus::Skipped => ['skip', 'gray'],
                };

                $this->line('  <fg='.$color.'>'.$mark.'</> '.OutputFormatter::escape($finding->message).' <fg=gray>['.$finding->code.']</>');

                if ($finding->remediation !== '') {
                    $this->line('       <fg=gray>remediation: '.OutputFormatter::escape($finding->remediation).'</>');
                }
            }
        }

        $summary = $report->summary();
        $this->newLine();
        $this->line(sprintf(
            '%d ok, %d warning, %d error, %d skipped',
            $summary['ok'],
            $summary['warning'],
            $summary['error'],
            $summary['skipped'],
        ));
    }
}
