<?php

declare(strict_types=1);

use Packman\Config\Data\CompileContext;
use Packman\Config\Internal\SectionCompiler;
use Packman\Testkit\Data\Support\StoredValue;
use Packman\Testkit\Fakes\Config\ReportTestSection;
use Packman\Testkit\Fakes\Config\ReportTestSettings;
use Packman\Testkit\Fakes\Context\MutableTenantResolver;
use Packman\Testkit\Fakes\Models\FixtureActor;
use Packman\Testkit\Fakes\Models\FixtureOwner;
use Packman\Testkit\Fakes\Observability\FakeMetricsRecorder;
use Packman\Testkit\Fakes\Observability\FakeTraceRecorder;
use Packman\Testkit\Support\Database\PreserveRepeatedGroups;
use Packman\Testkit\Support\Fixtures\CanonicalJson;
use Packman\Testkit\Support\Fixtures\FailingStream;
use Packman\Testkit\Support\Fixtures\Scale;

it('records metrics, spans, events, and tenant scoping accurately', function (): void {
    $metrics = new FakeMetricsRecorder;
    $metrics->incrementCounter('jobs.dispatched', 2, ['queue' => 'default']);
    $metrics->incrementCounter('jobs.dispatched', 3);
    $metrics->recordHistogram('jobs.latency', 12.5);
    $metrics->recordGauge('queue.depth', 4.0);
    $metrics->assertCounter('jobs.dispatched', 5);
    expect($metrics->histograms('jobs.latency'))->toHaveCount(1)
        ->and($metrics->gauges('queue.depth'))->toHaveCount(1);
    $metrics->reset();
    $metrics->assertCounter('jobs.dispatched', 0);

    $tracer = new FakeTraceRecorder;
    $spanId = $tracer->startSpan('http.request', ['route' => '/status']);
    $tracer->recordEvent($spanId, 'cache.hit');
    $tracer->endSpan($spanId, 'ok');
    $tracer->assertSpanRecorded('http.request');
    expect($tracer->spans('http.request')[0]['events'])->toHaveCount(1);

    $tenant = new MutableTenantResolver('tenant-a');
    $seen = $tenant->actingAsTenant('tenant-b', static fn (): ?string => $tenant->currentTenantId());
    expect($seen)->toBe('tenant-b')
        ->and($tenant->currentTenantId())->toBe('tenant-a');

    $actor = new FixtureActor('u1', 'Alice', 'tenant-a', ['admin']);
    $owner = new FixtureOwner('o1', 'acme', 'Acme Corp');
    expect($actor->roles)->toBe(['admin'])
        ->and($owner->slug)->toBe('acme')
        ->and(StoredValue::of(123)->present)->toBeTrue()
        ->and(StoredValue::absent()->present)->toBeFalse();
});

it('compiles ReportTestSection cleanly via SectionCompiler and exercises canonical fixtures', function (): void {
    $compiled = (new SectionCompiler)->compile(
        [new ReportTestSection],
        ['report_test' => ['enabled' => true, 'channel' => 'audit']],
        new CompileContext(
            environment: 'testing',
            defaultConnection: 'sqlite',
            connections: ['sqlite'],
            userModelFallback: null,
            allowUnsafe: false,
        ),
    );

    /** @var ReportTestSettings $settings */
    $settings = $compiled->get(ReportTestSettings::class);
    expect($settings)->toBeInstanceOf(ReportTestSettings::class)
        ->and($settings->enabled)->toBeTrue()
        ->and($settings->channel)->toBe('audit');

    expect(CanonicalJson::same(['b' => 2, 'a' => ['y' => 1, 'x' => 0]], ['a' => ['x' => 0, 'y' => 1], 'b' => 2]))->toBeTrue();

    $reordered = PreserveRepeatedGroups::reorder(
        [['g' => 'a', 'v' => 1], ['g' => 'b', 'v' => 2], ['g' => 'a', 'v' => 3]],
        static fn (array $row): string => $row['g'],
    );
    expect(array_column($reordered, 'v'))->toBe([1, 3, 2]);

    $url = FailingStream::seed('fixture.txt', 'hello-world', failReadAt: 5);
    $stream = fopen($url, 'rb');
    expect($stream)->not->toBeFalse();

    if ($stream !== false) {
        expect(fread($stream, 5))->toBe('hello')
            ->and(fread($stream, 4))->toBeFalse();
        fclose($stream);
    }
    FailingStream::reset();

    expect(Scale::count(10, minimum: 1))->toBeGreaterThanOrEqual(1);
});
