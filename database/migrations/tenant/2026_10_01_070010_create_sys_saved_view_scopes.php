<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sys_saved_view_scopes', function (Blueprint $table): void {
            $table->string('entity_code', 128);
            $table->string('scope_key', 1024);
            $table->primary(['entity_code', 'scope_key'], 'sys_saved_view_scopes_pk');
        });

        // PostgreSQL's Blueprint grammar ignores the requested primary-key name.
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement('ALTER TABLE sys_saved_view_scopes RENAME CONSTRAINT sys_saved_view_scopes_pkey TO sys_saved_view_scopes_pk');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sys_saved_view_scopes');
    }
};
