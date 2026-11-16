<?php

declare(strict_types=1);

namespace Packman\Modules\Tests\Stubs;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Packman\Features\Contracts\FeatureFlagsInterface;
use Packman\Features\Data\FeatureFlagDefinition;

final class StubDatabaseFeatureFlags implements FeatureFlagsInterface
{
    public function __construct(
        private readonly ?string $connection = null,
    ) {}

    public static function ensureTable(?string $connection = null): void
    {
        $schema = Schema::connection($connection);

        if ($schema->hasTable('sys_feature_flags')) {
            return;
        }

        $schema->create('sys_feature_flags', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('key')->unique();
            $table->boolean('is_enabled')->default(false);
            $table->string('module')->nullable();
            $table->jsonb('payload')->nullable();
            $table->timestamps();
        });
    }

    public function define(FeatureFlagDefinition $def): void
    {
        self::ensureTable($this->connection);

        $table = DB::connection($this->connection)->table('sys_feature_flags');

        if ($table->where('key', $def->key)->exists()) {
            return;
        }

        $now = now()->format('Y-m-d H:i:s.u');

        $table->insert([
            'id' => (string) Str::uuid7(),
            'key' => $def->key,
            'is_enabled' => $def->default,
            'module' => $def->module,
            'payload' => $def->payload !== null ? json_encode($def->payload->value, JSON_THROW_ON_ERROR) : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function active(string $key): bool
    {
        return (bool) DB::connection($this->connection)
            ->table('sys_feature_flags')
            ->where('key', $key)
            ->value('is_enabled');
    }
}
