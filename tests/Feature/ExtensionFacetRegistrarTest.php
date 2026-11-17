<?php

declare(strict_types=1);

use Packman\Config\Contracts\OptionsFactory;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Config\Laravel\ConfigSectionRegistration;
use Packman\Config\Laravel\ConfigurationValidator;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Doctor\Contracts\DoctorRegistryContract;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Data\DoctorFinding;
use Packman\Doctor\Laravel\DoctorChecks;
use Packman\Extensions\Concerns\Configuration\Configurable;
use Packman\Extensions\Concerns\Runtime\ProvidesDiagnostics;
use Packman\Extensions\Contracts\Configuration\WithConfiguration;
use Packman\Extensions\Contracts\Runtime\WithDiagnostics;
use Packman\Extensions\Laravel\ExtensionFacetRegistrar;

final readonly class FacetProbeOptions implements OptionsFactory
{
    public function __construct(public string $mode = 'strict') {}

    public static function fromArray(array $config, string $path = 'facet_probe'): self
    {
        $validator = new ConfigurationValidator;
        $validator->rejectUnknownKeys($config, ['mode'], $path);
        $mode = $config['mode'] ?? 'strict';

        if (! is_string($mode) || $mode === '') {
            $validator->add($path.'.mode', 'must be a non-empty string');
            $mode = 'strict';
        }

        $validator->failIfAny();

        return new self($mode);
    }
}

final class FacetProbeCheck implements DoctorCheck
{
    public function key(): string
    {
        return 'facet.probe.check';
    }

    public function title(): string
    {
        return 'Facet probe check';
    }

    public function run(DoctorContext $context): array
    {
        return [DoctorFinding::ok('facet.probe.ok', 'Healthy.')];
    }
}

final class SampleCoreExtension implements WithConfiguration, WithDiagnostics
{
    use Configurable;
    use ProvidesDiagnostics;

    public function configRoot(): string
    {
        return 'facet_probe';
    }

    public function optionsClass(): string
    {
        return FacetProbeOptions::class;
    }

    public function healthChecks(): array
    {
        return [FacetProbeCheck::class];
    }
}

it('validates unknown keys with Levenshtein typo suggestions in ConfigurationValidator', function (): void {
    $validator = new ConfigurationValidator;
    $validator->rejectUnknownKeys(['mde' => 'fast', 'extra' => 1], ['mode'], 'probe');

    expect(static fn () => $validator->failIfAny())
        ->toThrow(InvalidConfiguration::class, 'probe.mde: unknown key (did you mean "mode"?)');
});

it('registers WithConfiguration, WithDiagnostics, ConfigSectionRegistration, and DoctorChecks cleanly', function (): void {
    config()->set('facet_probe', ['mode' => 'fast']);

    ExtensionFacetRegistrar::register(app(), SampleCoreExtension::class);
    ConfigSectionRegistration::own(app(), 'facet_probe', static function (array $config): void {
        FacetProbeOptions::fromArray($config, 'facet_probe');
    });

    expect(app(FacetProbeOptions::class)->mode)->toBe('fast')
        ->and(array_map(static fn (DoctorCheck $c): string => $c->key(), app(DoctorRegistryContract::class)->matching(['facet'])))
        ->toBe(['facet.probe.check']);

    ConfigSectionRegistration::assertKnownKeys(app(), ['facet_probe' => ['mode' => 'fast']]);

    config()->set('facet_probe', ['mde' => 'broken']);
    $doctorFindings = app(DoctorRegistryContract::class)->matching(['core.config'])[0]->run(new DoctorContext);
    expect($doctorFindings[0]->code)->toBe('core.config.invalid')
        ->and($doctorFindings[0]->message)->toContain('did you mean "mode"?');

    expect(static fn () => DoctorChecks::register(app(), stdClass::class))
        ->toThrow(InvalidArgumentException::class);
});
