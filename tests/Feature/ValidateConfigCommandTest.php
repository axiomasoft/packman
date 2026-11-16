<?php

declare(strict_types=1);

use Packman\Config\Contracts\ConfigReportSectionInterface;
use Packman\Config\Contracts\OptionsFactory;
use Packman\Config\Contracts\RuntimeConfigCheck;
use Packman\Config\Data\SecretRef;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Config\Internal\Commands\ValidateConfigCommand;
use Symfony\Component\Console\Tester\CommandTester;

const PACKMAN_TEST_SECRET_DEFAULT = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

final readonly class ValidateTestOptions implements OptionsFactory
{
    public function __construct(public string $driver) {}

    public static function fromArray(array $config, string $path = 'probe'): self
    {
        $driver = $config['driver'] ?? 'null';

        if (! is_string($driver) || $driver === '') {
            throw new InvalidConfiguration(["{$path}.driver: must be a non-empty string"]);
        }

        if ($driver === 'super-secret-value-test') {
            throw new InvalidConfiguration(["{$path}.driver: driver super-secret-value-test is invalid"]);
        }

        return new self($driver);
    }
}

final class ValidateTestReportSection implements ConfigReportSectionInterface
{
    public function order(): int
    {
        return 1;
    }

    public function options(): array
    {
        return [ValidateTestOptions::class => ['probe']];
    }

    public function secretKeys(): array
    {
        return ['probe.test_secret', 'probe.test_default_secret'];
    }

    public function secretDefaults(): array
    {
        return ['probe.test_default_secret' => PACKMAN_TEST_SECRET_DEFAULT];
    }

    public function drivers(): array
    {
        $driver = config('probe.driver', 'null');

        return ['driver probe.driver='.(is_string($driver) ? $driver : 'absent')];
    }

    public function adapters(): array
    {
        $driver = config('probe.driver', 'null');
        $selected = $driver === 'redis';

        return [['line' => 'adapter probe.redis='.($selected ? 'available' : 'not-selected'), 'error' => null]];
    }
}

function runValidateConfigCommand(): array
{
    app()->singleton(ValidateTestOptions::class, static fn (): ValidateTestOptions => ValidateTestOptions::fromArray((array) config('probe', [])));
    app()->tag([ValidateTestReportSection::class], ConfigReportSectionInterface::class);

    $command = new ValidateConfigCommand;
    $command->setLaravel(app());
    $tester = new CommandTester($command);
    $exit = $tester->execute([], ['capture_stderr_separately' => true]);

    return [
        'stdout' => $tester->getDisplay(),
        'stderr' => $tester->getErrorOutput(),
        'exit' => $exit,
    ];
}

it('exits zero on valid configuration and reports drivers and absent secrets', function (): void {
    config(['probe' => ['driver' => 'null']]);

    $result = runValidateConfigCommand();

    expect($result['exit'])->toBe(0)
        ->and($result['stdout'])->toContain('driver probe.driver=null')
        ->and($result['stdout'])->toContain('adapter probe.redis=not-selected')
        ->and($result['stdout'])->toContain('secret probe.test_secret=absent');
});

it('redacts secret values from stdout and stderr when option validation fails', function (): void {
    $secret = 'super-secret-value-test';
    config([
        'probe' => ['driver' => $secret, 'test_secret' => new SecretRef($secret)],
    ]);

    $result = runValidateConfigCommand();
    $combined = $result['stdout'].$result['stderr'];

    expect($result['exit'])->toBe(1)
        ->and($result['stdout'])->toContain('secret probe.test_secret=present')
        ->and($result['stderr'])->toContain('driver present is invalid')
        ->and($combined)->not->toContain($secret);
});

it('warns on default secret values without printing them', function (): void {
    config(['probe' => ['driver' => 'null', 'test_default_secret' => PACKMAN_TEST_SECRET_DEFAULT]]);

    $result = runValidateConfigCommand();

    expect($result['exit'])->toBe(0)
        ->and($result['stdout'])->toContain('WARNING [secret-default] probe.test_default_secret')
        ->and($result['stdout'])->not->toContain(PACKMAN_TEST_SECRET_DEFAULT);
});

it('fails on RuntimeConfigCheck violation and reports acknowledged dangerous modes', function (): void {
    config(['probe' => ['driver' => 'redis'], 'packman.acknowledged_dangerous_modes' => ['sample.unsafe_mode']]);
    app()->bind('probe.runtime-check', static fn (): RuntimeConfigCheck => new class implements RuntimeConfigCheck
    {
        public function violations(bool $production): array
        {
            return ['probe.feature.secret: must be configured'];
        }
    });
    app()->tag(['probe.runtime-check'], RuntimeConfigCheck::class);

    $result = runValidateConfigCommand();

    expect($result['exit'])->toBe(1)
        ->and($result['stdout'])->toContain('WARNING [dangerous-mode-acknowledged] sample.unsafe_mode')
        ->and($result['stderr'])->toContain('probe.feature.secret: must be configured');
});
