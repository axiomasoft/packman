<?php

declare(strict_types=1);

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Events\EventServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Packman\Errors\Contracts\ProblemDefinitionProvider;
use Packman\Errors\Contracts\ProblemRegistry;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Data\ProblemDefinition;
use Packman\Errors\Enums\CoreErrorCode;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Errors\Exceptions\ProblemRegistryException;
use Packman\Errors\Internal\CompiledProblemRegistry;
use Packman\Errors\Internal\PackageProblemDefinitions;
use Packman\Errors\Internal\ProblemContribution;
use Packman\Errors\Laravel\ProblemDefinitions;
use Packman\PackmanServiceProvider;

test('ProblemRegistry boots with the isolated kernel and freezes its catalogue', function (): void {
    $previousContainer = Container::getInstance();
    $previousFacadeApp = Facade::getFacadeApplication();

    $isolatedApp = new Application(basePath: $this->app->basePath());
    $isolatedApp->instance('env', 'testing');
    $isolatedApp->instance('config', new ConfigRepository([]));
    $isolatedApp->register(EventServiceProvider::class);
    $isolatedApp->register(PackmanServiceProvider::class);

    try {
        $isolatedApp->boot();
        $isolatedRegistry = $isolatedApp->make(ProblemRegistry::class);

        expect($isolatedRegistry->get(CoreErrorCode::InternalError)->type)->toBe('urn:packman:problem:packman.internal_error')
            ->and($isolatedRegistry)->toBe($isolatedApp->make(ProblemRegistry::class))
            ->and(array_filter($isolatedRegistry->all(), static fn (ProblemDefinition $row): bool => ! str_starts_with($row->code->value(), 'packman.')))->toBe([]);
    } finally {
        Container::setInstance($previousContainer);
        Facade::setFacadeApplication($previousFacadeApp);
    }

    $registry = resolve(ProblemRegistry::class);
    expect($registry->get(CoreErrorCode::InternalError)->type)->toBe('urn:packman:problem:packman.internal_error')
        ->and($registry)->toBe(resolve(ProblemRegistry::class));

    ProblemDefinitions::register($this->app, dirname(__DIR__, 2).'/composer.json', PackageProblemDefinitions::class);

    expect(fn () => ProblemDefinitions::register($this->app, dirname(__DIR__, 2).'/composer.json', 'Packman\\Errors\\Internal\\LateProvider'))
        ->toThrow(ProblemRegistryException::class, 'ERROR-REGISTRY-FROZEN');
});

test('ProblemRegistry rejects invalid boot contributions with typed diagnostics', function (string $failure, array $definitions, string $owner): void {
    $provider = new readonly class($definitions) implements ProblemDefinitionProvider
    {
        /** @param list<ProblemDefinition> $rows */
        public function __construct(private array $rows) {}

        public function definitions(): iterable
        {
            return $this->rows;
        }
    };

    try {
        new CompiledProblemRegistry([new ProblemContribution($owner, $provider)]);
        $this->fail('Invalid boot contribution was accepted.');
    } catch (ProblemRegistryException $error) {
        expect($error->getMessage())->toStartWith($failure)
            ->and($error->errorDescriptor()->category)->toBe(ErrorCategory::Internal)
            ->and($error->errorDescriptor()->code)->toStartWith('packman.');
    }
})->with(function (): array {
    $valid = new ProblemDefinition(CoreErrorCode::InternalError, 'urn:packman:problem:packman.internal_error', 500, 'Safe failure.', false, ErrorCategory::Internal);

    return [
        'duplicate code' => ['ERROR-DUPLICATE-CODE', [$valid, $valid], 'packman'],
        'foreign prefix' => ['ERROR-FOREIGN-PREFIX', [$valid], 'foreign'],
        'duplicate URI' => ['ERROR-DUPLICATE-TYPE', [$valid, new ProblemDefinition(CoreErrorCode::UnknownCode, $valid->type, 500, 'Safe failure.', false, ErrorCategory::Internal)], 'packman'],
        'invalid status' => ['ERROR-INVALID-STATUS', [new ProblemDefinition(CoreErrorCode::InternalError, $valid->type, 200, 'Safe failure.', false, ErrorCategory::Internal)], 'packman'],
        'status category mismatch' => ['ERROR-INVALID-DEFINITION', [new ProblemDefinition(CoreErrorCode::InternalError, $valid->type, 404, 'Safe failure.', false, ErrorCategory::Internal)], 'packman'],
        'invalid URI' => ['ERROR-INVALID-DEFINITION', [new ProblemDefinition(CoreErrorCode::InternalError, 'relative', 500, 'Safe failure.', false, ErrorCategory::Internal)], 'packman'],
    ];
});

test('ProblemRegistry rejects enum outside declared source roots and honors configured urn_prefix', function (): void {
    $provider = new readonly class implements ProblemDefinitionProvider
    {
        public function definitions(): iterable
        {
            yield new ProblemDefinition(CoreErrorCode::InternalError, 'urn:packman:problem:packman.internal_error', 500, 'Safe failure.', false, ErrorCategory::Internal);
        }
    };

    expect(fn () => new CompiledProblemRegistry([new ProblemContribution('packman', $provider, 'axiomasoft/packman', ['/non-existent-root'])]))
        ->toThrow(ProblemRegistryException::class, 'ERROR-FOREIGN-TYPE');

    $previousContainer = Container::getInstance();
    $previousFacadeApp = Facade::getFacadeApplication();

    $customUrnApp = new Application(basePath: $this->app->basePath());
    $customUrnApp->instance('env', 'testing');
    $customUrnApp->instance('config', new ConfigRepository([
        'packman' => ['errors' => ['urn_prefix' => 'urn:example:problem:']],
    ]));
    $customUrnApp->register(EventServiceProvider::class);
    $customUrnApp->register(PackmanServiceProvider::class);

    try {
        $customUrnApp->boot();
        $registry = $customUrnApp->make(ProblemRegistry::class);
        expect($registry->get(CoreErrorCode::InternalError)->type)->toBe('urn:example:problem:packman.internal_error');
    } finally {
        Container::setInstance($previousContainer);
        Facade::setFacadeApplication($previousFacadeApp);
    }
});

test('ProblemRegistry rejects unknown persisted identities and descriptor category drift', function (): void {
    $registry = resolve(ProblemRegistry::class);

    expect(fn () => $registry->fromWire('user.secret'))->toThrow(ProblemRegistryException::class, 'ERROR-UNKNOWN-CODE')
        ->and(fn () => $registry->assertOccurrence(new ErrorDescriptor(CoreErrorCode::InternalError, ErrorCategory::Conflict)))
        ->toThrow(ProblemRegistryException::class, 'ERROR-OCCURRENCE-MISMATCH');
});

test('ProblemRegistry constructor types reject dynamic raw strings', function (): void {
    $raw = 'packman.internal_error';
    expect(fn () => new ErrorDescriptor($raw, ErrorCategory::Internal))->toThrow(TypeError::class);
});
