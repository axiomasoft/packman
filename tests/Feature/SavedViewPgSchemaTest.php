<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Packman\Context\Data\ScopeRef;
use Packman\Data\Laravel\JsonDocuments;
use Packman\Foundation\Internal\SystemClock;
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
    config(['database.default' => 'pgsql']);
    DB::statement('DROP SCHEMA IF EXISTS public CASCADE');
    DB::statement('CREATE SCHEMA public');
    $this->migrateSchemaSets(['axiomasoft/packman-panels:tenant'], 'pgsql');
});

it('creates the saved-view scope lock before the composite view foreign key', function (): void {
    expect(Schema::hasTable('sys_saved_view_scopes'))->toBeTrue()
        ->and(Schema::hasTable('sys_saved_views'))->toBeTrue();

    $foreignKeys = DB::select(
        "select constraint_name from information_schema.table_constraints where table_name = 'sys_saved_views' and constraint_type = 'FOREIGN KEY'",
    );

    expect($foreignKeys)->not->toBeEmpty();
})->group('pg');

it('locks scope FOR UPDATE before locking saved view rows across save, setDefault, and delete on PostgreSQL', function (): void {
    $policy = new class implements ViewAccessPolicy
    {
        public function allows(ViewContext $context, ViewAction $action, ViewSubject $subject): bool
        {
            return true;
        }
    };
    $schema = new ViewSchema('neutral/1', 'lab.sample', [
        new ViewField('name', 'string', ['eq', 'ne', 'in', 'is_null'], true, true, true, true, 'name'),
    ]);
    $schemas = new readonly class($schema) implements ViewSchemaProvider
    {
        public function __construct(private ViewSchema $schema) {}

        public function schema(ViewContext $context, string $entityHandle): ViewSchema
        {
            return $this->schema;
        }
    };

    $repository = new SqlSavedViewRepository(
        schemas: $schemas,
        policy: $policy,
        validator: new ViewConfigValidator,
        options: PanelsOptions::fromArray([]),
        database: resolve('db'),
        clock: new SystemClock,
    );
    $context = new ViewContext(new ScopeRef('account'), 'user', 'actor');
    $scope = new ViewScope('lab.sample', null, null, null);
    $config = JsonDocuments::capture(['version' => 'saved-view/1', 'filters' => null, 'sorts' => [], 'columns' => ['name'], 'groupBy' => null, 'presentation' => []]);

    $first = $repository->save($context, new SaveViewInput(null, '', $scope, 'table', ['en' => 'First'], $config));
    $second = $repository->save($context, new SaveViewInput(null, '', $scope, 'table', ['en' => 'Second'], $config));

    $connection = DB::connection('pgsql');
    $connection->enableQueryLog();

    $assertPgLockHierarchy = static function (array $queries): void {
        $forUpdates = array_values(array_filter(
            array_map(static fn (array $q): string => strtolower((string) $q['query']), $queries),
            static fn (string $sql): bool => str_contains($sql, 'for update'),
        ));

        expect($forUpdates)->toHaveCount(2)
            ->and($forUpdates[0])->toContain('"sys_saved_view_scopes"')
            ->and($forUpdates[1])->toContain('"sys_saved_views"');
    };

    $connection->flushQueryLog();
    $updated = $repository->save($context, new SaveViewInput($first->id, '1', $scope, 'table', ['en' => 'First v2'], $config));
    expect($updated->revision)->toBe('2');
    $assertPgLockHierarchy($connection->getQueryLog());

    $connection->flushQueryLog();
    $promoted = $repository->setDefault($context, $second->id);
    expect($promoted->isDefault)->toBeTrue();
    $assertPgLockHierarchy($connection->getQueryLog());

    $connection->flushQueryLog();
    $repository->delete($context, $second->id);
    $assertPgLockHierarchy($connection->getQueryLog());

    $remaining = $repository->viewsFor($context, $scope);
    expect($remaining)->toHaveCount(1)
        ->and($remaining[0]->id)->toBe($first->id)
        ->and($remaining[0]->isDefault)->toBeTrue();
})->group('pg');
