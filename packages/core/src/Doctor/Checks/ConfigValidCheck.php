<?php

declare(strict_types=1);

namespace Packman\Doctor\Checks;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Packman\Attributes\Internal;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Config\Laravel\RuntimeConfigGate;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Data\DoctorFinding;
use ReflectionFunction;
use Throwable;

/**
 * Runs tagged configuration validators (`packman.config_validators`) and runtime config checks.
 */
#[Internal]
final readonly class ConfigValidCheck implements DoctorCheck
{
    public const string TAG = 'packman.config_validators';

    public function __construct(private Application $app) {}

    public function key(): string
    {
        return 'core.config.valid';
    }

    public function title(): string
    {
        return 'Configuration';
    }

    public function run(DoctorContext $context): array
    {
        $failures = [];

        try {
            $violations = RuntimeConfigGate::violations($this->app, $context->production);

            if ($violations !== []) {
                $failures['runtime'] = implode('; ', $violations);
            }
        } catch (Throwable $exception) {
            $failures['runtime'] = $exception instanceof InvalidConfiguration ? $exception->getMessage() : $exception::class;
        }

        /** @var array<string, Closure(): void> $steps */
        $steps = [];
        $validatorCount = 1;
        foreach ($this->app->tagged(self::TAG) as $step) {
            if ($step instanceof Closure) {
                $validatorCount++;
                $name = new ReflectionFunction($step)->getClosureScopeClass()?->getName() ?? 'package';
                $unique = $name;
                for ($n = 2; isset($steps[$unique]); $n++) {
                    $unique = $name.'#'.$n;
                }
                /** @var Closure(): void $step */
                $steps[$unique] = $step;
            }
        }

        foreach ($steps as $name => $step) {
            try {
                $step();
            } catch (Throwable $exception) {
                $failures[$name] = $exception instanceof InvalidConfiguration ? $exception->getMessage() : $exception::class;
            }
        }

        if ($failures === []) {
            return [
                DoctorFinding::ok(
                    code: 'core.config.valid',
                    message: 'The configuration of every registered package is valid.',
                    evidence: ['validators' => $validatorCount],
                ),
            ];
        }

        return array_map(
            static fn (string $name, string $message): DoctorFinding => DoctorFinding::error(
                code: 'core.config.invalid',
                message: $message,
                remediation: 'Fix the invalid configuration key named in the message.',
                evidence: ['validator' => $name],
            ),
            array_keys($failures),
            array_values($failures),
        );
    }
}
