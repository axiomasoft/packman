<?php

declare(strict_types=1);

namespace Packman\Config\Internal\Commands;

use Closure;
use Illuminate\Console\Command;
use Packman\Attributes\Internal;
use Packman\Config\Contracts\ConfigReportSectionInterface;
use Packman\Config\Data\SecretRef;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Config\Laravel\RuntimeConfigGate;
use Packman\Doctor\Checks\ConfigValidCheck;
use Throwable;
use UnexpectedValueException;

/**
 * Prints effective drivers and adapter availability without secret values.
 */
#[Internal]
final class ValidateConfigCommand extends Command
{
    /** @var list<ConfigReportSectionInterface> */
    private array $sections = [];

    protected $signature = 'packman:config:validate {--format=text} {--production : Evaluate runtime checks as the production environment}';

    protected $description = 'Validate bound Options, print effective drivers and adapter availability, and never print secret values.';

    /** @var list<string> */
    private array $redactions = [];

    /** @var list<string> */
    private array $errors = [];

    /** @var array<string, true> */
    private array $defaultWarnings = [];

    public function handle(): int
    {
        $this->redactions = [];
        $this->errors = [];
        $this->defaultWarnings = [];

        $sections = [];
        foreach ($this->laravel->tagged(ConfigReportSectionInterface::class) as $section) {
            if (! $section instanceof ConfigReportSectionInterface) {
                throw new UnexpectedValueException('Configuration report contributor must implement ConfigReportSectionInterface.');
            }
            $sections[] = $section;
        }
        $this->sections = $sections;
        usort($this->sections, static fn (ConfigReportSectionInterface $a, ConfigReportSectionInterface $b): int => $a->order() <=> $b->order());

        $format = $this->option('format');

        if ($format !== 'text') {
            $this->writeError('format must be text');

            return self::FAILURE;
        }

        $this->collectSecrets();
        $this->validateBoundOptions();
        $this->writeDrivers();
        $this->writeAdapters();
        $this->writeSecrets();
        $this->writeRuntimeChecks();

        foreach ($this->errors as $error) {
            $this->writeError($error);
        }

        return $this->errors === [] ? self::SUCCESS : self::FAILURE;
    }

    private function collectSecrets(): void
    {
        foreach ($this->secretKeys() as $key) {
            $plain = $this->plaintext(config($key));

            if ($plain === null) {
                continue;
            }

            $this->redactions[] = $plain;

            if ($plain === ($this->secretDefaults()[$key] ?? null)) {
                $this->defaultWarnings[$key] = true;
            }
        }
    }

    private function validateBoundOptions(): void
    {
        foreach ($this->optionSources() as $class => $keys) {
            if (! class_exists($class) || ! $this->laravel->bound($class) || ! method_exists($class, 'fromArray')) {
                continue;
            }

            try {
                $arguments = array_map($this->configArray(...), $keys);
                $class::fromArray(...$arguments);
            } catch (InvalidConfiguration $exception) {
                foreach ($exception->violations as $violation) {
                    $this->errors[] = $this->redact($violation);
                }
            } catch (Throwable $exception) {
                $this->errors[] = $this->redact($exception->getMessage());
            }
        }

        foreach ($this->laravel->tagged(ConfigValidCheck::TAG) as $step) {
            if (! $step instanceof Closure) {
                continue;
            }

            try {
                $step();
            } catch (InvalidConfiguration $exception) {
                foreach ($exception->violations as $violation) {
                    $this->errors[] = $this->redact($violation);
                }
            } catch (Throwable $exception) {
                $this->errors[] = $this->redact($exception->getMessage());
            }
        }
    }

    private function writeDrivers(): void
    {
        foreach ($this->sections as $section) {
            foreach ($section->drivers() as $line) {
                $this->writeLine($line);
            }
        }
    }

    private function writeAdapters(): void
    {
        foreach ($this->sections as $section) {
            foreach ($section->adapters() as $adapter) {
                $this->writeLine($adapter['line']);

                if ($adapter['error'] !== null) {
                    $this->errors[] = $adapter['error'];
                }
            }
        }
    }

    /** @return list<string> */
    private function secretKeys(): array
    {
        $parts = array_map(static fn (ConfigReportSectionInterface $section): array => $section->secretKeys(), $this->sections);

        return $parts === [] ? [] : array_merge([], ...$parts);
    }

    /** @return array<string, string> */
    private function secretDefaults(): array
    {
        $parts = array_map(static fn (ConfigReportSectionInterface $section): array => $section->secretDefaults(), $this->sections);

        return $parts === [] ? [] : array_merge([], ...$parts);
    }

    /** @return array<class-string, list<string>> */
    private function optionSources(): array
    {
        $parts = array_map(static fn (ConfigReportSectionInterface $section): array => $section->options(), $this->sections);

        return $parts === [] ? [] : array_merge([], ...$parts);
    }

    private function writeRuntimeChecks(): void
    {
        $production = $this->option('production') === true || $this->laravel->environment('production');

        foreach (RuntimeConfigGate::violations(app: $this->laravel, production: $production) as $violation) {
            $this->errors[] = $violation;
        }

        $acknowledged = config('packman.acknowledged_dangerous_modes', []);

        if (is_array($acknowledged)) {
            foreach ($acknowledged as $path) {
                if (is_string($path) && $path !== '') {
                    $this->writeWarning('WARNING [dangerous-mode-acknowledged] '.$path);
                }
            }
        }
    }

    private function writeSecrets(): void
    {
        foreach ($this->secretKeys() as $key) {
            $present = $this->plaintext(config($key)) !== null;
            $this->writeLine('secret '.$key.'='.($present ? 'present' : 'absent'));

            if (isset($this->defaultWarnings[$key])) {
                $this->writeWarning('WARNING [secret-default] '.$key);
            }
        }
    }

    private function plaintext(mixed $value): ?string
    {
        if ($value instanceof SecretRef) {
            return $value->isPresent() ? $value->reveal() : null;
        }

        if (is_string($value) && $value !== '') {
            return $value;
        }

        return null;
    }

    /** @return array<array-key, mixed> */
    private function configArray(string $key): array
    {
        if (! config()->has($key)) {
            return [];
        }

        $value = config($key);

        if (! is_array($value)) {
            throw new InvalidConfiguration([$key.': expected an array']);
        }

        return $value;
    }

    private function redact(string $text): string
    {
        foreach ($this->redactions as $secret) {
            if ($secret !== '') {
                $text = str_replace($secret, 'present', $text);
            }
        }

        return $text;
    }

    private function writeLine(string $line): void
    {
        $this->line($this->redact($line));
    }

    private function writeWarning(string $line): void
    {
        $this->warn($this->redact($line));
    }

    private function writeError(string $line): void
    {
        $this->output->getErrorStyle()->writeln($this->redact($line));
    }
}
