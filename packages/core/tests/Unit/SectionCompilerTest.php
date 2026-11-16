<?php

declare(strict_types=1);

use Packman\Config\Contracts\ConfigReader;
use Packman\Config\Contracts\ConfigSection;
use Packman\Config\Data\CompileContext;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Config\Internal\SectionCompiler;

function sectionTestContext(string $env = 'local', bool $allow = false): CompileContext
{
    return new CompileContext($env, 'sqlite', ['sqlite'], 'App\\Models\\User', $allow);
}

/** @return list<ConfigSection> */
function sampleSections(): array
{
    return [new SampleRouteSection, new SampleMessagesSection, new SampleForumSection];
}

it('compiles env integers, missing values and nullable defaults without a container', function (): void {
    $compiled = (new SectionCompiler)->compile(sampleSections(), [
        'sample' => ['route' => ['prefix' => 'api/v1/sample', 'middleware' => ['api']], 'messages' => ['max_nodes' => '2000']],
        'sample-forum' => ['space' => 'forum', 'forum_mode' => 'flat_with_reply_refs', 'max_links' => null, 'types' => ['topic' => ['slow_mode' => 5]]],
    ], sectionTestContext());

    expect($compiled->get(SampleMessageLimits::class)->maxNodes)->toBe(2000)
        ->and($compiled->get(SampleForumSettings::class)->maxLinks)->toBeNull()
        ->and($compiled->get(SampleForumSettings::class)->types)->toBe(['topic' => 5])
        ->and($compiled->unsafePaths)->toBe([]);
});

it('aggregates all problems by path with Levenshtein suggestions and no secret values', function (): void {
    try {
        (new SectionCompiler)->compile(sampleSections(), [
            'sample' => ['route' => ['prefx' => 'x', 'middleware' => 'api'], 'messages' => ['max_nodes' => 0, 'max_depth' => 65, 'max_content_bytes' => 'big'], 'webhooks' => []],
            'sample-forum' => ['space' => 'default', 'forum_mode' => 'tree', 'types' => ['topic' => ['slow_mode' => -1, 'slowmode' => 1]], 'api_token' => 'sekrit'],
        ], sectionTestContext());
        test()->fail('Expected invalid configuration');
    } catch (InvalidConfiguration $e) {
        expect($e->problems())->toBe([
            'sample-forum.api_token: unknown key',
            'sample-forum.forum_mode: must be one of [flat, flat_with_reply_refs], got "tree"',
            'sample-forum.space: must name a dedicated space, not "default"',
            'sample-forum.types.topic.slow_mode: must be between 0 and 3600, got -1',
            'sample-forum.types.topic.slowmode: unknown key',
            'sample.messages.max_content_bytes: must be an integer, got "big"',
            'sample.messages.max_depth: must be between 1 and 64, got 65',
            'sample.messages.max_nodes: must be between 1 and 100000, got 0',
            'sample.route.middleware: must be a list of strings, got "api"',
            'sample.route.prefx: unknown key (did you mean "prefix"?)',
            'sample.webhooks: unknown key',
        ])->and($e->getMessage())->not->toContain('sekrit');
    }
});

it('records unsafe paths in local and explicitly allowed production', function (string $env, bool $allow): void {
    $compiled = (new SectionCompiler)->compile(sampleSections(), ['sample' => ['messages' => ['unsafe_unbounded' => true]]], sectionTestContext($env, $allow));
    expect($compiled->unsafePaths)->toBe(['sample.messages.unsafe_unbounded']);
})->with([['local', false], ['production', true]]);

it('refuses unsafe production settings without an override', function (): void {
    try {
        (new SectionCompiler)->compile(sampleSections(), ['sample' => ['messages' => ['unsafe_unbounded' => true]]], sectionTestContext('production'));
        test()->fail('Expected production guard');
    } catch (InvalidConfiguration $e) {
        expect($e->problems())->toBe([
            'sample.messages.unsafe_unbounded: unsafe setting is not allowed in production (set packman.unsafe.allow_in_production / PACKMAN_ALLOW_UNSAFE=true to accept the risk)',
        ]);
    }
});

it('rejects duplicate settings producers', function (): void {
    (new SectionCompiler)->compile([new SampleRouteSection, new SampleRouteSection], [], sectionTestContext());
})->throws(InvalidConfiguration::class, 'is produced by two sections');

it('rejects a section returning a different settings class', function (): void {
    $section = new class implements ConfigSection
    {
        public function key(): string
        {
            return 'sample.route';
        }

        public function settingsClass(): string
        {
            return SampleRouteSettings::class;
        }

        public function compile(ConfigReader $config): object
        {
            return new stdClass;
        }
    };
    (new SectionCompiler)->compile([$section], [], sectionTestContext());
})->throws(InvalidConfiguration::class, '::compile() must return');

it('collects each callback exceptions at the item path', function (): void {
    $section = new class implements ConfigSection
    {
        public function key(): string
        {
            return 'sample';
        }

        public function settingsClass(): string
        {
            return stdClass::class;
        }

        public function compile(ConfigReader $config): object
        {
            $config->each('types', static fn (ConfigReader $child): never => throw InvalidConfiguration::forMessage('bad type'));

            return new stdClass;
        }
    };
    (new SectionCompiler)->compile([$section], ['sample' => ['types' => ['topic' => []]]], sectionTestContext());
})->throws(InvalidConfiguration::class, 'sample.types.topic: bad type');

it('redacts known secret values in errors, nested maps, lists, and schema defaults', function (): void {
    $section = new class implements ConfigSection
    {
        public function key(): string
        {
            return 'sec';
        }

        public function settingsClass(): string
        {
            return stdClass::class;
        }

        public function compile(ConfigReader $config): object
        {
            $config->string('api_key', 'default-secret-123');
            $config->stringMap('credentials', []);
            $config->classList('tokens', [], stdClass::class);
            $config->float('ratio', 0.5, 0.0, 1.0);
            $config->stringListMap('grants', [], ['member', 'owner'], ['send_message']);

            return new stdClass;
        }
    };

    try {
        (new SectionCompiler)->compile([$section], [
            'sec' => [
                'api_key' => 999999,
                'credentials' => ['api_key' => 123456789],
                'tokens' => ['NonExistentClass'],
                'ratio' => NAN,
                'grants' => ['ghost' => [], 'member' => ['ghost_action'], 'owner' => 'send_message'],
            ],
        ], sectionTestContext());
        test()->fail('Expected validation failures');
    } catch (InvalidConfiguration $e) {
        $message = $e->getMessage();
        expect($message)->toContain('sec.api_key: must be a string, got <redacted>')
            ->and($message)->toContain('sec.credentials: must be a map of non-empty strings, got <redacted>')
            ->and($message)->toContain('sec.tokens.0: names an unknown class, got <redacted>')
            ->and($message)->toContain('sec.ratio: must be a finite number between 0 and 1')
            ->and($message)->toContain('sec.grants.ghost: unknown map key [ghost]')
            ->and($message)->toContain('sec.grants.member.0: must be a non-empty allowed string')
            ->and($message)->toContain('sec.grants.owner: must be a list of strings')
            ->and($message)->not->toContain('999999')
            ->and($message)->not->toContain('123456789')
            ->and($message)->not->toContain('NonExistentClass');
    }

    $compiled = (new SectionCompiler)->compile([$section], [
        'sec' => ['grants' => ['member' => ['send_message'], 'owner' => []]],
    ], sectionTestContext());
    $schemaByPath = array_column($compiled->schema, null, 'path');

    expect($schemaByPath['sec.api_key']['default'])->toBe('[redacted]');
});

final readonly class SampleRouteSettings
{
    /** @param list<string> $middleware */
    public function __construct(public string $prefix, public array $middleware) {}
}

enum SampleMode: string
{
    case Flat = 'flat';
    case Refs = 'flat_with_reply_refs';
}

final readonly class SampleForumSettings
{
    /** @param array<string, int> $types */
    public function __construct(public string $space, public SampleMode $mode, public ?int $maxLinks, public array $types) {}
}

final class SampleRouteSection implements ConfigSection
{
    public function key(): string
    {
        return 'sample.route';
    }

    public function settingsClass(): string
    {
        return SampleRouteSettings::class;
    }

    public function compile(ConfigReader $config): SampleRouteSettings
    {
        return new SampleRouteSettings($config->string('prefix', 'api/v1/sample', pattern: '#^[a-z0-9/_-]+$#'), $config->stringList('middleware', ['api']));
    }
}

final class SampleForumSection implements ConfigSection
{
    public function key(): string
    {
        return 'sample-forum';
    }

    public function settingsClass(): string
    {
        return SampleForumSettings::class;
    }

    public function compile(ConfigReader $config): SampleForumSettings
    {
        $space = $config->string('space', 'forum');

        if ($space === 'default') {
            $config->fail('space', 'must name a dedicated space, not "default"');
        }

        return new SampleForumSettings(
            $space,
            SampleMode::from($config->oneOf('forum_mode', 'flat', ['flat', 'flat_with_reply_refs'])),
            $config->nullableInt('max_links', null, 0, 1000),
            $config->each('types', static fn (ConfigReader $t): int => $t->int('slow_mode', 0, 0, 3600)),
        );
    }
}

final class SampleMessagesSection implements ConfigSection
{
    public function key(): string
    {
        return 'sample.messages';
    }

    public function settingsClass(): string
    {
        return SampleMessageLimits::class;
    }

    public function compile(ConfigReader $config): SampleMessageLimits
    {
        return new SampleMessageLimits(
            maxLength: $config->int('max_length', 4000, min: 1, max: 100_000),
            maxNodes: $config->int('max_nodes', 5000, min: 1, max: 100_000),
            maxContentBytes: $config->int('max_content_bytes', 65_536, min: 256, max: 16_777_216),
            maxDepth: $config->int('max_depth', 64, min: 1, max: 64),
            unbounded: $config->unsafeBool('unsafe_unbounded'),
        );
    }
}

final readonly class SampleMessageLimits
{
    public function __construct(
        public int $maxLength,
        public int $maxNodes,
        public int $maxContentBytes,
        public int $maxDepth,
        public bool $unbounded = false,
    ) {}
}
