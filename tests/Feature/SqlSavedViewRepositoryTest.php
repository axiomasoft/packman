<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Packman\Context\Data\ScopeRef;
use Packman\Data\Laravel\JsonDocuments;
use Packman\Foundation\Internal\SystemClock;
use Packman\Panels\Exceptions\SavedViews\SavedViewException;
use Packman\Panels\Internal\Configuration\PanelsOptions;
use Packman\Panels\SavedViews\Contracts\ViewAccessPolicy;
use Packman\Panels\SavedViews\Contracts\ViewSchemaProvider;
use Packman\Panels\SavedViews\Data\SaveViewInput;
use Packman\Panels\SavedViews\Data\ViewAction;
use Packman\Panels\SavedViews\Data\ViewContext;
use Packman\Panels\SavedViews\Data\ViewField;
use Packman\Panels\SavedViews\Data\ViewSchema;
use Packman\Panels\SavedViews\Data\ViewScope;
use Packman\Panels\SavedViews\Data\ViewSubject;
use Packman\Panels\SavedViews\Internal\SqlSavedViewRepository;
use Packman\Panels\SavedViews\Internal\ViewConfigValidator;

beforeEach(function (): void {
    $this->migrateSchemaSets(['axiomasoft/packman-panels:tenant']);
});

function svAllowPolicy(): ViewAccessPolicy
{
    return new class implements ViewAccessPolicy
    {
        public function allows(ViewContext $context, ViewAction $action, ViewSubject $subject): bool
        {
            return true;
        }
    };
}

function svSchemaProvider(): ViewSchemaProvider
{
    $schema = new ViewSchema('neutral/1', 'lab.sample', [
        new ViewField('name', 'string', ['eq', 'ne', 'in', 'is_null'], true, true, true, true, 'name'),
    ]);

    return new readonly class($schema) implements ViewSchemaProvider
    {
        public function __construct(private ViewSchema $schema) {}

        public function schema(ViewContext $context, string $entityHandle): ViewSchema
        {
            return $this->schema;
        }
    };
}

/**
 * @return array{0: SqlSavedViewRepository, 1: DatabaseManager, 2: ViewContext, 3: ViewScope}
 */
function savedViewConnectionProbe(Application $app, ?string $override = null): array
{
    foreach (['central', 'tenant', 'pinned'] as $name) {
        $app['config']->set('database.connections.'.$name, [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    $manager = new class($app, $app->make('db.factory')) extends DatabaseManager
    {
        public string $current = 'central';

        /** @var list<string> */
        public array $selected = [];

        public function getDefaultConnection()
        {
            return $this->current;
        }

        public function connection($name = null)
        {
            $resolved = is_string($name) && $name !== '' ? $name : $this->current;
            $this->selected[] = $resolved;

            return parent::connection($resolved);
        }
    };

    foreach (['central', 'tenant', 'pinned'] as $name) {
        $manager->connection($name)->getSchemaBuilder()->create('sys_saved_views', function (Blueprint $table): void {
            $table->string('entity_code');
            $table->string('scope_key');
            $table->integer('position');
        });
    }

    $manager->selected = [];
    $options = PanelsOptions::fromArray($override === null ? [] : ['views' => ['connection' => $override]]);
    $repository = new SqlSavedViewRepository(
        schemas: svSchemaProvider(),
        policy: svAllowPolicy(),
        validator: new ViewConfigValidator,
        options: $options,
        database: $manager,
        clock: new SystemClock,
    );

    return [
        $repository,
        $manager,
        new ViewContext(new ScopeRef('account'), 'user', 'actor'),
        new ViewScope('lab.sample', null, null, null),
    ];
}

it('persists only a validated AST and compiles it without SQL or identifiers', function (): void {
    $repository = new SqlSavedViewRepository(
        svSchemaProvider(),
        svAllowPolicy(),
        new ViewConfigValidator,
        PanelsOptions::fromArray([]),
        resolve('db'),
        new SystemClock,
    );
    $context = new ViewContext(new ScopeRef('account'), 'user', 'actor');
    $scope = new ViewScope('lab.sample', null, null, null);

    $view = $repository->save($context, new SaveViewInput(null, '', $scope, 'table', ['en' => 'Sample'], JsonDocuments::capture([
        'version' => 'saved-view/1',
        'filters' => ['field' => 'name', 'op' => 'eq', 'value' => 'safe'],
        'sorts' => [],
        'columns' => ['name'],
        'groupBy' => null,
        'presentation' => [],
    ])));

    expect($view->revision)->toBe('1')
        ->and($view->isDefault)->toBeTrue()
        ->and(JsonDocuments::array($repository->compile($context, $view->id)->filters))->toBe(['field' => 'name', 'op' => 'eq', 'value' => 'safe']);
});

it('resolves the current default connection dynamically and keeps explicit connections pinned', function (): void {
    [$dynamicRepo, $dynamicDb, $context, $scope] = savedViewConnectionProbe($this->app);

    foreach (['central', 'tenant', 'central'] as $default) {
        $dynamicDb->current = $default;
        expect($dynamicRepo->viewsFor($context, $scope))->toBe([]);
    }

    expect($dynamicDb->selected)->toBe(['central', 'tenant', 'central']);

    [$pinnedRepo, $pinnedDb, $pContext, $pScope] = savedViewConnectionProbe($this->app, 'pinned');

    foreach (['central', 'tenant', 'central'] as $default) {
        $pinnedDb->current = $default;
        expect($pinnedRepo->viewsFor($pContext, $pScope))->toBe([]);
    }

    expect($pinnedDb->selected)->toBe(['pinned', 'pinned', 'pinned'])
        ->and(PanelsOptions::fromArray(['views' => ['connection' => 'pinned']])->viewsConnection)->toBe('pinned');
});

it('updates saved views optimistically, replaces deleted defaults, and denies when access policy refuses', function (): void {
    $denyPolicy = new class implements ViewAccessPolicy
    {
        public bool $allow = true;

        public function allows(ViewContext $context, ViewAction $action, ViewSubject $subject): bool
        {
            return $this->allow;
        }
    };

    $repository = new SqlSavedViewRepository(
        svSchemaProvider(),
        $denyPolicy,
        new ViewConfigValidator,
        PanelsOptions::fromArray([]),
        resolve('db'),
        new SystemClock,
    );
    $context = new ViewContext(new ScopeRef('account'), 'user', 'actor');
    $scope = new ViewScope('lab.sample', null, null, null);
    $config = JsonDocuments::capture(['version' => 'saved-view/1', 'filters' => null, 'sorts' => [], 'columns' => ['name'], 'groupBy' => null, 'presentation' => []]);

    $first = $repository->save($context, new SaveViewInput(null, '', $scope, 'table', ['en' => 'First'], $config));
    $second = $repository->save($context, new SaveViewInput(null, '', $scope, 'table', ['en' => 'Second'], $config));
    expect($repository->viewsFor($context, $scope))->toHaveCount(2)->and($second->isDefault)->toBeFalse();

    $updated = $repository->save($context, new SaveViewInput($first->id, '1', $scope, 'table', ['en' => 'Updated'], $config));
    expect($updated->revision)->toBe('2')
        ->and(fn (): mixed => $repository->save($context, new SaveViewInput($first->id, '1', $scope, 'table', ['en' => 'Stale'], $config)))
        ->toThrow(SavedViewException::class, 'conflict');

    expect($repository->setDefault($context, $second->id)->isDefault)->toBeTrue();
    $repository->delete($context, $second->id);
    $rows = $repository->viewsFor($context, $scope);
    expect($rows)->toHaveCount(1)->and($rows[0]->isDefault)->toBeTrue();

    $denyPolicy->allow = false;
    expect(fn (): mixed => $repository->viewsFor($context, $scope))->toThrow(SavedViewException::class, 'denied');
    $denyPolicy->allow = true;

    $repository->delete($context, $first->id);
    expect($repository->viewsFor($context, $scope))->toBe([])
        ->and(fn (): mixed => $repository->setDefault($context, $first->id))->toThrow(SavedViewException::class, 'not found')
        ->and(fn (): mixed => $repository->compile($context, $first->id))->toThrow(SavedViewException::class, 'not found');
});

it('acquires the scope lock before locking or mutating view rows across save, setDefault, and delete', function (): void {
    $repository = new SqlSavedViewRepository(
        svSchemaProvider(),
        svAllowPolicy(),
        new ViewConfigValidator,
        PanelsOptions::fromArray([]),
        resolve('db'),
        new SystemClock,
    );
    $context = new ViewContext(new ScopeRef('account'), 'user', 'actor');
    $scope = new ViewScope('lab.sample', null, null, null);
    $config = JsonDocuments::capture(['version' => 'saved-view/1', 'filters' => null, 'sorts' => [], 'columns' => ['name'], 'groupBy' => null, 'presentation' => []]);

    $first = $repository->save($context, new SaveViewInput(null, '', $scope, 'table', ['en' => 'First'], $config));
    $second = $repository->save($context, new SaveViewInput(null, '', $scope, 'table', ['en' => 'Second'], $config));

    $connection = DB::connection();
    $connection->enableQueryLog();

    $assertScopeBeforeViewWrite = static function (array $queries): void {
        $sqls = array_map(static fn (array $q): string => strtolower((string) $q['query']), $queries);
        $scopeLockIdx = null;

        foreach ($sqls as $idx => $sql) {
            if (str_contains($sql, '"sys_saved_view_scopes"') && str_starts_with($sql, 'select')) {
                $scopeLockIdx = $idx;
            }
        }

        expect($scopeLockIdx)->not->toBeNull();

        foreach ($sqls as $idx => $sql) {
            if (str_contains($sql, '"sys_saved_views"') && (str_starts_with($sql, 'update') || str_starts_with($sql, 'delete'))) {
                expect($idx)->toBeGreaterThan((int) $scopeLockIdx);
            }
        }

        // For updates/deletes/setDefault, the locked view select follows the scope lock.
        $viewSelectsAfterScope = array_filter(
            array_slice($sqls, (int) $scopeLockIdx + 1),
            static fn (string $sql): bool => str_starts_with($sql, 'select') && str_contains($sql, '"sys_saved_views"'),
        );
        expect($viewSelectsAfterScope)->not->toBeEmpty();
    };

    $connection->flushQueryLog();
    $repository->save($context, new SaveViewInput($first->id, '1', $scope, 'table', ['en' => 'First Updated'], $config));
    $assertScopeBeforeViewWrite($connection->getQueryLog());

    $connection->flushQueryLog();
    $repository->setDefault($context, $second->id);
    $assertScopeBeforeViewWrite($connection->getQueryLog());

    $connection->flushQueryLog();
    $repository->delete($context, $second->id);
    $assertScopeBeforeViewWrite($connection->getQueryLog());
});
