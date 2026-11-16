<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sys_saved_views', function (Blueprint $table): void {
            $table->uuid('id');
            $table->primary(['id'], 'sys_saved_views_pk');
            $table->string('entity_code', 128);
            $table->string('workspace_id', 255)->nullable();
            $table->string('owner_kind', 64)->nullable();
            $table->string('owner_id', 255)->nullable();
            $table->string('scope_key', 1024);
            $table->string('kind', 64);
            $table->jsonb('name');
            $table->jsonb('config');
            $table->string('schema_version', 128);
            $table->unsignedBigInteger('revision');
            $table->boolean('is_default');
            $table->string('default_scope_key', 1024)->nullable();
            $table->bigInteger('position');
            $table->string('created_by', 128);
            $table->timestampsTz(precision: 6);
            $table->unique(['entity_code', 'default_scope_key'], 'sys_saved_views_default_scope_uq');
            $table->foreign(['entity_code', 'scope_key'], 'sys_saved_views_scope_fk')->references(['entity_code', 'scope_key'])->on('sys_saved_view_scopes');
        });

        // PostgreSQL's Blueprint grammar ignores the requested primary-key name.
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement('ALTER TABLE sys_saved_views RENAME CONSTRAINT sys_saved_views_pkey TO sys_saved_views_pk');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sys_saved_views');
    }
};
