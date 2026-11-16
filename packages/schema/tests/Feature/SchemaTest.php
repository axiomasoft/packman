<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Schema\Contracts\SchemaOwnership;
use Packman\Schema\Data\ConstraintNames;
use Packman\Schema\Data\SchemaFingerprint;
use Packman\Schema\Data\TableNames;
use Packman\Schema\Laravel\PackmanMigration;

uses()->group('pg');

/** @return list<string> */
function constraintsOf(string $table): array
{
    return array_map(
        static fn (object $row): string => (string) $row->conname,
        DB::connection('pgsql')->select('select conname from pg_constraint where conrelid = ?::regclass order by conname', [$table]),
    );
}

/** @return list<string> */
function indexesOf(string $table): array
{
    return array_map(
        static fn (object $row): string => (string) $row->indexname,
        DB::connection('pgsql')->select('select indexname from pg_indexes where tablename = ? order by indexname', [$table]),
    );
}

function usePrefix(string $prefix): void
{
    config()->set('packman.schema.table_prefix', $prefix);
    app()->forgetInstance(TableNames::class);
}

beforeEach(function (): void {
    DB::connection('pgsql')->statement('DROP SCHEMA IF EXISTS public CASCADE');
    DB::connection('pgsql')->statement('CREATE SCHEMA public');
    DB::purge('pgsql');

    config()->set('packman.schema.connection', 'pgsql');
    config()->set('packman.schema.table_prefix', 'pmn_');
    app()->forgetInstance(TableNames::class);

    $ownership = app(SchemaOwnership::class);
    $ownership->declare('packman.core', ['store', 'channel', 'schema', 'operation', 'outbox']);

    $migration = new class extends PackmanMigration
    {
        public function up(): void
        {
            $state = $this->create('schema_state', function (Blueprint $table): void {
                $table->string('key', 64)->primary();
                $table->jsonb('value');
                $this->timestampsTz($table);
            });

            DB::connection('pgsql')->table($state)->insert([
                'key' => SchemaFingerprint::KEY,
                'value' => json_encode(SchemaFingerprint::document('pmn_'), JSON_THROW_ON_ERROR),
            ]);

            $this->create('operation_receipts', function (Blueprint $table): void {
                $this->uuidPrimary($table);
                $this->timestampsTz($table);
                $this->index($table, 'created_at');
            });

            $outbox = $this->create('outbox_messages', function (Blueprint $table): void {
                $this->identityPrimary($table);
                $table->uuid('event_id');
                $table->unsignedInteger('attempts')->default(0);
                $table->unsignedInteger('version')->default(1);
                $table->timestampTz('available_at', 6)->useCurrent();
                $table->timestampTz('delivered_at', 6)->nullable();
                $this->unique($table, 'event_id');
                $this->index($table, ['available_at', 'id']);
                $this->index($table, 'delivered_at');
            });

            $this->check($outbox, 'attempts_not_negative', 'attempts >= 0');
            $this->check($outbox, 'version_positive', 'version > 0');
        }
    };

    $migration->up();
});

it('names constraints by the canon and shortens long names deterministically', function (): void {
    expect(ConstraintNames::index('pmn_stock_levels', ['stock_item_id', 'location_id']))->toBe('pmn_stock_levels_stock_item_id_location_id_ix')
        ->and(ConstraintNames::unique('pmn_orders', 'cart_id'))->toBe('pmn_orders_cart_id_uq')
        ->and(ConstraintNames::foreign('pmn_channels', 'store_id'))->toBe('pmn_channels_store_id_fk')
        ->and(ConstraintNames::check('pmn_stock_levels', 'reserved_not_negative'))->toBe('pmn_stock_levels_reserved_not_negative_ck')
        ->and(ConstraintNames::exclusion('pmn_prices', 'validity_overlap'))->toBe('pmn_prices_validity_overlap_ex')
        ->and(ConstraintNames::primary('pmn_orders'))->toBe('pmn_orders_pk');

    $long = 'pmn_tax_rates_'.str_repeat('x', 50).'_ex';
    $exclusion = ConstraintNames::exclusion('pmn_tax_rates', str_repeat('x', 50));

    expect($exclusion)->toHaveLength(63)
        ->toBe(substr($long, 0, 54).'_'.substr(sha1($long), 0, 8));

    $columns = ['promotion_id', 'customer_ref', 'coupon_id', 'order_ref'];
    $full = 'pmn_promotion_customer_usages_promotion_id_customer_ref_coupon_id_order_ref_uq';
    $short = ConstraintNames::unique('pmn_promotion_customer_usages', $columns);

    expect($short)->toHaveLength(63)
        ->toBe(substr($full, 0, 54).'_'.substr(sha1($full), 0, 8))
        ->and(ConstraintNames::unique('pmn_promotion_customer_usages', $columns))->toBe($short);

    expect(static fn (): string => ConstraintNames::index('pmn_x', []))->toThrow(InvalidArgumentException::class)
        ->and(static fn (): string => ConstraintNames::index('pmn_x', 'Bad-Column'))->toThrow(InvalidArgumentException::class);
});

it('resolves logical tables of declared nouns only', function (): void {
    $tables = app(TableNames::class);

    expect($tables->table('outbox_messages'))->toBe('pmn_outbox_messages')
        ->and($tables->table('stores'))->toBe('pmn_stores')
        ->and($tables->table('schema_state'))->toBe('pmn_schema_state')
        ->and($tables->prefix())->toBe('pmn_')
        ->and($tables->connection())->toBe('pgsql');

    expect(static fn (): string => $tables->table('stock_levels'))->toThrow(LogicException::class, 'has no owner')
        ->and(static fn (): string => $tables->table('Stock-Levels'))->toThrow(InvalidArgumentException::class)
        ->and(static fn (): string => $tables->table('outbox_'.str_repeat('x', 60)))->toThrow(InvalidArgumentException::class, '63 bytes');
});

it('records noun ownership and refuses a noun claimed twice', function (): void {
    $ownership = app(SchemaOwnership::class);
    $ownership->declare('packman.inventory', ['stock']);
    $ownership->declare('acme.loyalty', ['x_acme']);

    expect($ownership->owners()['packman.core'])->toBe(['store', 'channel', 'schema', 'operation', 'outbox'])
        ->and($ownership->ownerOf('stock_levels'))->toBe('packman.inventory')
        ->and($ownership->ownerOf('stores'))->toBe('packman.core')
        ->and($ownership->ownerOf('operation_receipts'))->toBe('packman.core')
        ->and($ownership->ownerOf('x_acme_points'))->toBe('acme.loyalty')
        ->and($ownership->ownerOf('stockpile'))->toBeNull()
        ->and($ownership->ownerOf('orders'))->toBeNull();

    expect(static fn () => $ownership->declare('acme.other', ['stock']))
        ->toThrow(InvalidConfiguration::class, "noun 'stock' is already owned by 'packman.inventory'");
});

it('creates core tables with canonical constraint names and the install fingerprint', function (): void {
    expect(constraintsOf('pmn_schema_state'))->toBe(['pmn_schema_state_pk'])
        ->and(constraintsOf('pmn_operation_receipts'))->toBe(['pmn_operation_receipts_pk'])
        ->and(indexesOf('pmn_operation_receipts'))->toBe(['pmn_operation_receipts_created_at_ix', 'pmn_operation_receipts_pk'])
        ->and(constraintsOf('pmn_outbox_messages'))->toBe([
            'pmn_outbox_messages_attempts_not_negative_ck',
            'pmn_outbox_messages_event_id_uq',
            'pmn_outbox_messages_pk',
            'pmn_outbox_messages_version_positive_ck',
        ])
        ->and(indexesOf('pmn_outbox_messages'))->toContain('pmn_outbox_messages_available_at_id_ix', 'pmn_outbox_messages_delivered_at_ix');

    $identity = DB::connection('pgsql')->selectOne("select is_identity, identity_generation from information_schema.columns where table_name = 'pmn_outbox_messages' and column_name = 'id'");
    expect($identity?->is_identity)->toBe('YES')->and($identity?->identity_generation)->toBe('ALWAYS');

    $stored = json_decode((string) DB::connection('pgsql')->table('pmn_schema_state')->where('key', 'fingerprint')->value('value'), true, flags: JSON_THROW_ON_ERROR);
    expect($stored)->toEqual(SchemaFingerprint::document('pmn_'))
        ->and($stored['id_strategy'])->toBe('uuid7')
        ->and($stored['schema_version'])->toBe(1)
        ->and($stored['hash'])->toMatch('/\A[0-9a-f]{64}\z/');
});

it('refuses a prefix that differs from the installed one', function (): void {
    usePrefix('shp_');

    expect(static fn (): string => app(TableNames::class)->table('operation_receipts'))
        ->toThrow(static function (InvalidConfiguration $e): void {
            expect($e->getMessage())->toContain("installed with prefix 'pmn_'");
        });
});

it('ignores host tables that only look like a state table', function (): void {
    DB::connection('pgsql')->statement('create table abc_schema_state (key text primary key, value text)');
    DB::connection('pgsql')->table('abc_schema_state')->insert(['key' => 'fingerprint', 'value' => '{"prefix":"zzz_"}']);
    DB::connection('pgsql')->statement('alter table pmn_schema_state rename to old_schema_state');

    usePrefix('new_');

    expect(app(TableNames::class)->table('outbox_messages'))->toBe('new_outbox_messages');
});

it('gives migrations helpers for keys, money, refs, timestamps, checks, and exclusion constraints', function (): void {
    app(SchemaOwnership::class)->declare('acme.test', ['x_test']);

    $migration = new class extends PackmanMigration
    {
        public function up(): void
        {
            $table = $this->create('x_test_items', function (Blueprint $table): void {
                $this->uuidPrimary($table);
                $this->ref($table, 'sellable');
                $this->ref($table, 'customer', nullable: true);
                $this->money($table, 'price');
                $this->money($table, 'discount');
                $table->integer('quantity');
                $this->unique($table, ['sellable_ref', 'customer_ref']);
                $this->timestampsTz($table);
            });

            $this->check($table, 'quantity_positive', 'quantity > 0');
            $this->db()->statement('create extension if not exists btree_gist');
            $this->exclude($table, 'sellable_once', 'sellable_ref with =');
        }

        public function down(): void
        {
            $this->drop('x_test_items');
        }
    };

    $migration->up();

    $columns = collect(DB::connection('pgsql')->select("select column_name, data_type, character_maximum_length, is_nullable from information_schema.columns where table_name = 'pmn_x_test_items'"))
        ->keyBy('column_name');

    expect($columns->keys()->sort()->values()->all())->toBe([
        'created_at', 'currency', 'customer_ref', 'discount_minor', 'id', 'price_minor', 'quantity', 'sellable_ref', 'updated_at',
    ])
        ->and($columns['id']->data_type)->toBe('uuid')
        ->and($columns['price_minor']->data_type)->toBe('bigint')
        ->and($columns['currency']->data_type)->toBe('character')
        ->and($columns['currency']->character_maximum_length)->toBe(3)
        ->and($columns['sellable_ref']->character_maximum_length)->toBe(191)
        ->and($columns['customer_ref']->is_nullable)->toBe('YES')
        ->and($columns['created_at']->data_type)->toBe('timestamp with time zone')
        ->and($columns['updated_at']->is_nullable)->toBe('NO')
        ->and(constraintsOf('pmn_x_test_items'))->toBe([
            'pmn_x_test_items_pk',
            'pmn_x_test_items_quantity_positive_ck',
            'pmn_x_test_items_sellable_once_ex',
            'pmn_x_test_items_sellable_ref_customer_ref_uq',
        ])
        ->and($migration->getConnection())->toBe('pgsql');

    $migration->down();

    expect(DB::connection('pgsql')->selectOne("select to_regclass('pmn_x_test_items') as t")?->t)->toBeNull();
});
