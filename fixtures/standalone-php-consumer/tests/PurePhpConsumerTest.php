<?php

declare(strict_types=1);

namespace Fixture\StandalonePhp\Tests;

use Fixture\StandalonePhp\ConsumerConfigSection;
use Fixture\StandalonePhp\ConsumerErrorCode;
use Fixture\StandalonePhp\ConsumerExtension;
use Fixture\StandalonePhp\ConsumerOptions;
use Fixture\StandalonePhp\ConsumerPayloadException;
use Fixture\StandalonePhp\ConsumerProblemDefinitions;
use Fixture\StandalonePhp\ConsumerSettings;
use Fixture\StandalonePhp\ConsumerSynchronizedEvent;
use Packman\Config\Data\CompileContext;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Config\Internal\ConfigSections;
use Packman\Config\Internal\SectionCompiler;
use Packman\Context\Contracts\ActorResolverInterface;
use Packman\Context\Contracts\ContextResolverInterface;
use Packman\Context\Contracts\ContextScopeInterface;
use Packman\Context\Data\ActorRef;
use Packman\Context\Data\ScopeRef;
use Packman\Context\Exceptions\ScopeUnavailableException;
use Packman\Errors\Enums\CoreErrorCode;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Errors\Internal\CompiledProblemRegistry;
use Packman\Errors\Internal\PackageProblemDefinitions;
use Packman\Errors\Internal\ProblemContribution;
use Packman\Extensions\ExtensionInspector;
use PHPUnit\Framework\TestCase;

final class PurePhpConsumerTest extends TestCase
{
    public function test_zero_illuminate_or_laravel_packages_installed(): void
    {
        $root = dirname(__DIR__);
        $lock = json_decode((string) file_get_contents($root.'/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($lock);

        $allInstalled = [];
        foreach (['packages', 'packages-dev'] as $section) {
            foreach ($lock[$section] ?? [] as $pkg) {
                self::assertIsArray($pkg);
                $name = $pkg['name'] ?? '';
                self::assertIsString($name);
                $allInstalled[] = $name;
                self::assertStringStartsNotWith('illuminate/', $name);
                self::assertStringStartsNotWith('laravel/', $name);
            }
        }

        self::assertContains('axiomasoft/packman', $allInstalled);

        $installedJson = json_decode((string) file_get_contents($root.'/vendor/composer/installed.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($installedJson);
        foreach ($installedJson['packages'] ?? [] as $pkg) {
            $name = $pkg['name'] ?? '';
            self::assertStringStartsNotWith('illuminate/', (string) $name);
            self::assertStringStartsNotWith('laravel/', (string) $name);
        }

        self::assertFalse(interface_exists('Illuminate\\Contracts\\Foundation\\Application'));
    }

    public function test_errors_context_events_and_extensions_work_in_pure_php(): void
    {
        // Errors: CompiledProblemRegistry + CodedException
        $registry = new CompiledProblemRegistry([
            new ProblemContribution('packman', new PackageProblemDefinitions, 'axiomasoft/packman'),
            new ProblemContribution('consumer', new ConsumerProblemDefinitions, 'fixture/standalone-php-consumer'),
        ]);

        self::assertSame(422, $registry->get(ConsumerErrorCode::InvalidPayload)->defaultStatus);
        self::assertSame('axiomasoft/packman', $registry->owner(CoreErrorCode::InvalidConfiguration));

        $exception = new ConsumerPayloadException('email');
        $descriptor = $exception->errorDescriptor();
        $registry->assertOccurrence($descriptor, 422);
        self::assertSame('consumer.invalid_payload', $descriptor->code);
        self::assertSame('/email', $descriptor->path);

        // Context: ScopeRef, ActorRef, ContextResolverInterface, ActorResolverInterface, ScopeUnavailableException
        $scope = new ScopeRef('tenant-101', ['region' => 'eu']);
        $actor = new ActorRef('service', 'worker-1');

        $contextResolver = new readonly class($scope) implements ContextResolverInterface
        {
            public function __construct(private ContextScopeInterface $scope) {}

            public function current(): ContextScopeInterface
            {
                return $this->scope;
            }
        };
        $actorResolver = new readonly class($actor) implements ActorResolverInterface
        {
            public function __construct(private ActorRef $actor) {}

            public function current(): ActorRef
            {
                return $this->actor;
            }
        };

        self::assertSame('tenant-101', $contextResolver->current()->id());
        self::assertSame('service', $actorResolver->current()->type);
        $unavailable = new ScopeUnavailableException;
        self::assertSame(ErrorCategory::Conflict, $unavailable->errorDescriptor()->category);

        // Events: DomainEvent UUIDv7 + UTC timestamp + scopeId
        $event = new ConsumerSynchronizedEvent($contextResolver->current());
        self::assertSame('consumer.synchronized', $event::name());
        self::assertSame('tenant-101', $event->scopeId);
        self::assertSame('UTC', $event->occurredAt->getTimezone()->getName());
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $event->eventId);

        // Extensions: WithConfiguration + Configurable via ExtensionInspector
        $facetProfile = ExtensionInspector::inspect(new ConsumerExtension);
        self::assertTrue($facetProfile->hasConfiguration);
        self::assertSame('consumer', $facetProfile->configRoot);
        self::assertSame(ConsumerOptions::class, $facetProfile->optionsClass);
        self::assertSame(ConsumerConfigSection::class, $facetProfile->configSectionClass);
    }

    public function test_config_options_sections_and_section_compiler_work_in_pure_php(): void
    {
        $options = ConsumerOptions::fromArray([
            'table_prefix' => 'csm_',
            'api_token' => 'top-secret-token',
            'timeout_seconds' => 45,
        ]);

        self::assertSame('csm_records', $options->tablePrefix->table('records'));
        self::assertSame('[redacted]', (string) $options->apiToken);
        self::assertSame('top-secret-token', $options->apiToken->reveal());
        self::assertSame(45, $options->timeoutSeconds);

        $sections = new ConfigSections;
        $sections->own('consumer', static function (array $config): void {
            ConsumerOptions::fromArray($config);
        });
        $sections->assertKnownKeys(['consumer' => ['table_prefix' => 'csm_', 'api_token' => 'x', 'timeout_seconds' => 10]]);

        $compiler = new SectionCompiler;
        $context = new CompileContext('testing', 'sqlite', ['sqlite'], null, false);
        $compiled = $compiler->compile(
            [new ConsumerConfigSection],
            ['consumer' => ['endpoint' => 'https://pure.test', 'retries' => 2]],
            $context,
        );

        /** @var ConsumerSettings $settings */
        $settings = $compiled->get(ConsumerSettings::class);
        self::assertSame('https://pure.test', $settings->endpoint);
        self::assertSame(2, $settings->retries);

        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('did you mean "endpoint"');
        $compiler->compile(
            [new ConsumerConfigSection],
            ['consumer' => ['endpont' => 'https://typo.test']],
            $context,
        );
    }
}
