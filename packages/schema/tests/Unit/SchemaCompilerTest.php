<?php

declare(strict_types=1);

use Packman\Schema\Contracts\SchemaStep;
use Packman\Schema\Data\SchemaSpace;
use Packman\Schema\Enums\IdStrategy;
use Packman\Schema\Exceptions\InvalidSchemaException;
use Packman\Schema\Internal\SchemaCompiler;
use Packman\Schema\Tests\Fixtures\FixtureComponent;
use Packman\Schema\Tests\Fixtures\FixtureStep;

/**
 * @param  list<string>  $tables
 * @param  array<string, class-string<SchemaStep>>  $steps
 * @param  list<string>  $deps
 */
function schemaComponent(string $id, array $tables, array $steps, array $deps = [], ?string $only = null): FixtureComponent
{
    return new FixtureComponent($id, $tables, $steps, $deps, $only);
}

/**
 * @param  list<FixtureComponent>  $components
 * @param  list<SchemaSpace>  $spaces
 * @param  array<string, string>  $identities
 * @return list<string>
 */
function schemaProblems(array $components, array $spaces, array $identities = ['app' => 'sqlite::memory:@app']): array
{
    try {
        (new SchemaCompiler)->compile($components, $spaces, $identities);
    } catch (InvalidSchemaException $exception) {
        return $exception->problems();
    }

    return [];
}

/** @param array<string, string> $tables */
function defaultSpace(string $prefix = 'pmn', array $tables = []): SchemaSpace
{
    return new SchemaSpace('default', true, 'app', '', IdStrategy::Uuid7, $prefix, $tables);
}

it('orders components by dependency then id and plans migrations by name', function (): void {
    $compiled = (new SchemaCompiler)->compile(
        [
            schemaComponent('poll', ['poll_votes'], ['2026_10_01_000301_create_poll_votes' => FixtureStep::class], ['core']),
            schemaComponent('core', ['channels'], ['2026_10_01_000001_create_space_state' => FixtureStep::class]),
        ],
        [defaultSpace()],
        ['app' => 'sqlite::memory:@app'],
    );

    expect(array_keys($compiled->components))->toBe(['core', 'poll'])
        ->and($compiled->plan('default')[0]->migration)->toBe('2026_10_01_000001_packman_default_core_create_space_state')
        ->and($compiled->plan('default')[1]->migration)->toBe('2026_10_01_000301_packman_default_poll_create_poll_votes')
        ->and($compiled->referenceable('poll'))->toBe(['poll_votes', 'channels']);
});

it('plans only the components that apply to a space', function (): void {
    $compiled = (new SchemaCompiler)->compile(
        [
            schemaComponent('core', ['channels'], ['2026_10_01_000001_create_channels' => FixtureStep::class]),
            schemaComponent('forum', ['topics'], ['2026_10_01_000401_create_topics' => FixtureStep::class], ['core'], 'forum'),
        ],
        [defaultSpace(), new SchemaSpace('forum', false, 'app', '', IdStrategy::Uuid7, 'pmn_forum')],
        ['app' => 'sqlite::memory:@app'],
    );

    expect($compiled->applicable)->toBe(['default' => ['core'], 'forum' => ['core', 'forum']])
        ->and($compiled->applies('default', 'forum'))->toBeFalse()
        ->and(count($compiled->plan('forum')))->toBe(2)
        ->and($compiled->plan('forum')[1]->migration)->toBe('2026_10_01_000401_packman_forum_forum_create_topics');
});

it('compiles a table override that names no logical table of a compiled component and keeps it on the space', function (): void {
    $compiled = (new SchemaCompiler)->compile(
        [schemaComponent('core', ['channels'], ['2026_10_01_000001_create_channels' => FixtureStep::class])],
        [defaultSpace('pmn', ['forum_topics' => 'legacy_topics'])],
        ['app' => 'sqlite::memory:@app'],
    );

    expect($compiled->space('default')?->tables)->toBe(['forum_topics' => 'legacy_topics']);
});

it('keeps the space fingerprint independent of the connection but not of the id strategy', function (): void {
    $crm = new SchemaSpace('crm', false, 'app', '', IdStrategy::Bigint, 'crm_chat', ['attachments' => 'crm_files']);
    $moved = new SchemaSpace('crm', false, 'eu', 'x_', IdStrategy::Bigint, 'crm_chat', ['attachments' => 'crm_files']);
    $rekeyed = new SchemaSpace('crm', false, 'app', '', IdStrategy::Uuid7, 'crm_chat', ['attachments' => 'crm_files']);

    expect($moved->fingerprint())->toBe($crm->fingerprint())
        ->and($rekeyed->fingerprint())->not->toBe($crm->fingerprint())
        ->and($crm->table('attachments'))->toBe('crm_files')
        ->and($crm->table('messages'))->toBe('crm_chat_messages')
        ->and($moved->databaseTable('messages'))->toBe('x_crm_chat_messages');
});

it('reports every problem at once', function (): void {
    $step = FixtureStep::class;
    /** @var array<string, class-string<SchemaStep>> $invalidSteps */
    $invalidSteps = ['2026_10_01_000002_b' => $step, '2026_10_01_000001_a' => stdClass::class, 'v1_c' => $step];

    $problems = schemaProblems(
        [
            schemaComponent('core', ['channels'], ['2026_10_01_000001_create_channels' => $step]),
            schemaComponent('core', ['x'], ['2026_10_01_000001_x' => $step]),
            schemaComponent('Bad-Id', ['y'], ['2026_10_01_000001_y' => $step]),
            schemaComponent('dup', ['channels', 'Bad Table'], $invalidSteps),
            schemaComponent('empty', ['e'], []),
            schemaComponent('ghost', ['g'], ['2026_10_02_000000_g' => $step], ['missing']),
            schemaComponent('a', ['a_t'], ['2026_10_03_000000_a' => $step], ['b']),
            schemaComponent('b', ['b_t'], ['2026_10_04_000000_b' => $step], ['a']),
        ],
        [
            defaultSpace('pmn', ['nope' => 'x']),
            new SchemaSpace('crm-x', false, 'app', '', IdStrategy::Uuid7, 'pmn'),
            new SchemaSpace('crm_x', false, 'app', '', IdStrategy::Uuid7, 'crm'),
            new SchemaSpace('main', true, 'app', '', IdStrategy::Uuid7, 'main'),
            new SchemaSpace('far', false, 'nowhere', '', IdStrategy::Uuid7, 'far'),
            new SchemaSpace('mssql', false, 'ms', '', IdStrategy::Uuid7, 'ms'),
        ],
        ['app' => 'sqlite::memory:@app', 'ms' => 'unsupported:sqlsrv@ms'],
    );

    expect($problems)->toContain('component [core] is registered twice')
        ->toContain('component id [Bad-Id] must match /^[a-z][a-z0-9_]{0,23}$/')
        ->toContain('component [dup]: logical table [Bad Table] must match /^[a-z][a-z0-9_]{0,39}$/')
        ->toContain('component [dup]: step [2026_10_01_000001_a] class must implement '.SchemaStep::class)
        ->toContain('component [dup]: step [2026_10_01_000001_a] must be later than the step before it')
        ->toContain('component [dup]: step id [v1_c] must be YYYY_MM_DD_HHMMSS_<snake_name>')
        ->toContain('component [empty] declares no steps')
        ->toContain('component [ghost] depends on unknown component [missing]')
        ->toContain('components depend on each other in a cycle: [a, b]')
        ->toContain('logical table [channels] is declared by [core] and [dup]')
        ->toContain('space [far]: connection [nowhere] is not configured')
        ->toContain('space [main]: only the space with id [default] is the default space')
        ->toContain('space [mssql]: connection [ms] uses an unsupported driver (pgsql, mysql, sqlite)')
        ->toContain('spaces [crm-x] and [crm_x] share the migration slug [crm_x]');
});

it('requires a dependent step to be later than the first step of its dependency', function (): void {
    expect(schemaProblems(
        [
            schemaComponent('core', ['channels'], ['2026_10_01_000001_create_channels' => FixtureStep::class]),
            schemaComponent('early', ['early_t'], ['2026_09_01_000000_early' => FixtureStep::class], ['core']),
        ],
        [defaultSpace()],
    ))->toBe(['component [early]: first step must be later than the first step of its dependency [core]']);
});

it('rejects a dependent that applies in a space without its dependency', function (): void {
    expect(schemaProblems(
        [
            schemaComponent('core', ['channels'], ['2026_10_01_000001_create_channels' => FixtureStep::class], [], 'default'),
            schemaComponent('poll', ['poll_votes'], ['2026_10_01_000301_create_poll_votes' => FixtureStep::class], ['core']),
        ],
        [defaultSpace(), new SchemaSpace('crm', false, 'app', '', IdStrategy::Uuid7, 'crm')],
    ))->toBe(['space [crm]: component [poll] applies but its dependency [core] does not']);
});

it('reports tables claimed by two spaces of one database', function (): void {
    $problems = schemaProblems(
        [schemaComponent('core', ['channels'], ['2026_10_01_000001_create_channels' => FixtureStep::class])],
        [defaultSpace(), new SchemaSpace('forum', false, 'app', '', IdStrategy::Uuid7, 'pmn')],
    );

    expect($problems)->toBe([
        'table [pmn_channels] on [sqlite::memory:@app] is claimed by [default/core] and [forum/core]; give one space another tablePrefix()/connection or map the table',
    ]);
});

it('rejects a table name longer than 63 bytes', function (): void {
    $prefix = str_repeat('p', 60);

    expect(schemaProblems(
        [schemaComponent('core', ['channels'], ['2026_10_01_000001_create_channels' => FixtureStep::class])],
        [new SchemaSpace('long', false, 'app', '', IdStrategy::Uuid7, $prefix)],
    ))->toBe(["space [long]: table [{$prefix}_channels] must be an identifier of at most 63 bytes"]);
});
