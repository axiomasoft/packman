<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Artisan;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Doctor\Contracts\HealthCheck;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Data\DoctorFinding;
use Packman\Doctor\Enums\CheckStatus;
use Packman\Doctor\Internal\DoctorRegistry;
use Packman\Doctor\Internal\DoctorReportBuilder;

final readonly class StubClosureDoctorCheck implements DoctorCheck
{
    /** @param Closure(DoctorContext): list<DoctorFinding> $callback */
    public function __construct(private string $key, private Closure $callback) {}

    public function key(): string
    {
        return $this->key;
    }

    public function title(): string
    {
        return $this->key;
    }

    public function run(DoctorContext $context): array
    {
        return ($this->callback)($context);
    }
}

/** @param list<DoctorCheck> $checks @param list<HealthCheck> $healthChecks */
function buildTestDoctorRegistry(array $checks, array $healthChecks = []): DoctorRegistry
{
    $container = new Container;

    foreach ($checks as $index => $check) {
        $container->instance('doctor.check.'.$index, $check);
    }
    $container->tag(array_map(static fn (int $i): string => 'doctor.check.'.$i, array_keys($checks)), DoctorRegistry::TAG);

    foreach ($healthChecks as $index => $check) {
        $container->instance('doctor.health.'.$index, $check);
    }
    $container->tag(array_map(static fn (int $i): string => 'doctor.health.'.$i, array_keys($healthChecks)), DoctorRegistry::HEALTH_CHECK_TAG);

    return new DoctorRegistry($container);
}

function okDoctorCheck(string $key): DoctorCheck
{
    return new StubClosureDoctorCheck($key, static fn (): array => [DoctorFinding::ok(code: $key.'.ok', message: 'fine')]);
}

it('orders checks by key, rejects duplicates, and filters by exact dotted segments', function (): void {
    $registry = buildTestDoctorRegistry([
        okDoctorCheck('core.schema.tables'),
        okDoctorCheck('core.schema.key_types'),
        okDoctorCheck('core.schemaX'),
        okDoctorCheck('drive.scan.policy'),
    ]);

    expect(array_map(static fn (DoctorCheck $c): string => $c->key(), $registry->all()))
        ->toBe(['core.schema.key_types', 'core.schema.tables', 'core.schemaX', 'drive.scan.policy'])
        ->and(array_map(static fn (DoctorCheck $c): string => $c->key(), $registry->matching(['core.schema'])))
        ->toBe(['core.schema.key_types', 'core.schema.tables'])
        ->and($registry->unmatched(['core.schema', 'nope', 'core.sch']))->toBe(['nope', 'core.sch']);

    expect(fn () => buildTestDoctorRegistry([okDoctorCheck('dup.key'), okDoctorCheck('dup.key')])->all())
        ->toThrow(LogicException::class, 'dup.key');
});

it('enforces scalar-only evidence, finite floats, and non-empty remediation on warnings and errors', function (): void {
    expect(fn () => DoctorFinding::warning(code: 'w', message: 'missing hint', remediation: ''))
        ->toThrow(InvalidArgumentException::class, 'requires a non-empty remediation')
        ->and(fn () => DoctorFinding::ok(code: 'o', message: 'nested', evidence: ['bad' => ['nested']]))
        ->toThrow(InvalidArgumentException::class, 'finite scalar or null values only')
        ->and(fn () => DoctorFinding::ok(code: 'o', message: 'nan', evidence: ['bad' => NAN]))
        ->toThrow(InvalidArgumentException::class, 'finite scalar or null values only')
        ->and(fn () => DoctorFinding::ok(code: 'o', message: 'inf', evidence: ['bad' => INF]))
        ->toThrow(InvalidArgumentException::class, 'finite scalar or null values only');
});

it('turns an exception into doctor.check_failed without leaking its message', function (): void {
    $builder = new DoctorReportBuilder(buildTestDoctorRegistry([
        new StubClosureDoctorCheck('fake.throws', static fn (): array => throw new RuntimeException('password=hunter2')),
        okDoctorCheck('fake.ok'),
    ]));

    $quiet = $builder->build(new DoctorContext);
    $verbose = $builder->build(new DoctorContext, exposeMessages: true);
    $failed = $quiet->checks[1]->findings[0];

    expect($quiet->checks[1]->key)->toBe('fake.throws')
        ->and($failed->status)->toBe(CheckStatus::Error)
        ->and($failed->code)->toBe('doctor.check_failed')
        ->and($failed->message)->toBe(RuntimeException::class)
        ->and(json_encode($quiet->toArray()))->not->toContain('hunter2')
        ->and($verbose->checks[1]->detail)->toBe('password=hunter2')
        ->and(json_encode($verbose->toArray()))->not->toContain('hunter2')
        ->and($quiet->exitCode())->toBe(1);
});

it('cascade-skips database-dependent checks and health slices when connection fails', function (): void {
    $dbHealth = new class implements HealthCheck
    {
        public function code(): string
        {
            return 'DOC-DB-HEALTH';
        }

        public function requiresDatabase(): bool
        {
            return true;
        }

        public function slice(DoctorContext $context): array
        {
            return [[], ['ran' => true]];
        }
    };

    $independentHealth = new class implements HealthCheck
    {
        public function code(): string
        {
            return 'DOC-INDEPENDENT';
        }

        public function requiresDatabase(): bool
        {
            return false;
        }

        public function slice(DoctorContext $context): array
        {
            return [[DoctorFinding::ok('doc.independent.ok', 'ok')], ['ran' => true]];
        }
    };

    $builder = new DoctorReportBuilder(buildTestDoctorRegistry(
        [
            new StubClosureDoctorCheck('core.database.connection', static fn (): array => [
                DoctorFinding::error('core.database.connection_failed', 'DB down', 'Check DB.'),
            ]),
            okDoctorCheck('core.schema.tables'),
        ],
        [$dbHealth, $independentHealth],
    ));

    $report = $builder->build(new DoctorContext);

    expect($report->checks[1]->status)->toBe(CheckStatus::Skipped)
        ->and($report->slices['DOC-DB-HEALTH'])->toBe(['status' => 'skipped_connection_failed'])
        ->and($report->slices['DOC-INDEPENDENT'])->toBe(['ran' => true]);
});

it('cascade-skips database-dependent health checks when an earlier health check emits DOC-CONNECT-001', function (): void {
    $connectHealth = new class implements HealthCheck
    {
        public function code(): string
        {
            return 'DOC-CONNECT';
        }

        public function requiresDatabase(): bool
        {
            return false;
        }

        public function slice(DoctorContext $context): array
        {
            return [[DoctorFinding::error('DOC-CONNECT-001', 'DB unreachable', 'Check DSN.')], ['ok' => false]];
        }
    };

    $dbHealth = new class implements HealthCheck
    {
        public function code(): string
        {
            return 'DOC-SCHEMA';
        }

        public function requiresDatabase(): bool
        {
            return true;
        }

        public function slice(DoctorContext $context): array
        {
            return [[DoctorFinding::ok('doc.schema.ok', 'ok')], ['ran' => true]];
        }
    };

    $report = (new DoctorReportBuilder(buildTestDoctorRegistry([], [$connectHealth, $dbHealth])))->build(new DoctorContext);

    expect($report->slices['DOC-SCHEMA'])->toBe(['status' => 'skipped_connection_failed'])
        ->and($report->checks[1]->key)->toBe('doc_schema')
        ->and($report->checks[1]->status)->toBe(CheckStatus::Skipped);
});

it('validates packman:doctor --json output including contributed HealthChecks against doctor-v1.schema.json', function (): void {
    app()->instance('test.health', new class implements HealthCheck
    {
        public function code(): string
        {
            return 'DOC-INDEPENDENT';
        }

        public function requiresDatabase(): bool
        {
            return false;
        }

        public function slice(DoctorContext $context): array
        {
            return [[DoctorFinding::ok('doc.independent.ok', 'healthy')], ['status' => 'ok']];
        }
    });
    app()->tag(['test.health'], DoctorRegistry::HEALTH_CHECK_TAG);

    Artisan::call('packman:doctor', ['--json' => true]);
    $document = json_decode(Artisan::output(), false, 512, JSON_THROW_ON_ERROR);
    $schema = json_decode((string) file_get_contents(__DIR__.'/../../resources/schemas/doctor-v1.schema.json'), false, 512, JSON_THROW_ON_ERROR);

    $result = (new Validator)->validate($document, $schema);
    $error = $result->error();

    expect($result->isValid())->toBeTrue($error === null ? '' : json_encode((new ErrorFormatter)->format($error)))
        ->and($document->schema)->toBe('packman.doctor/v1')
        ->and($document->checks)->not->toBeEmpty()
        ->and($document->slices->{'DOC-INDEPENDENT'}->status)->toBe('ok');
});

it('prints doctor output and returns exit code 2 on unknown prefix', function (): void {
    expect(Artisan::call('packman:doctor', ['--prefix' => ['core.config']]))->toBe(0)
        ->and(Artisan::call('packman:doctor', ['--prefix' => ['does.not.exist']]))->toBe(2);
});
