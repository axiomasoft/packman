<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mod_lifecycle_log', function (Blueprint $table): void {
            $table->uuid('id');
            $table->primary(['id'], 'mod_lifecycle_log_pk');
            $table->string('module_name', 190);
            $table->string('operation', 16);
            $table->string('from_state', 16);
            $table->string('to_state', 16);
            $table->string('status', 16)->default('success');
            $table->string('actor_type', 64);
            $table->string('actor_id', 191)->nullable();
            $table->jsonb('details')->default('{}');
            $table->timestampTz('created_at', precision: 6)->useCurrent();
            $table->timestampTz('updated_at', precision: 6)->useCurrent();
        });

        Schema::getConnection()->statement('ALTER TABLE mod_lifecycle_log RENAME CONSTRAINT mod_lifecycle_log_pkey TO mod_lifecycle_log_pk');

        DB::statement('CREATE INDEX mod_lifecycle_log_module_ix ON mod_lifecycle_log (module_name, created_at DESC)');
        DB::statement("ALTER TABLE mod_lifecycle_log ADD CONSTRAINT mod_lifecycle_log_operation_ck CHECK (operation IN ('enable','disable','upgrade','archive','purge'))");
        DB::statement("ALTER TABLE mod_lifecycle_log ADD CONSTRAINT mod_lifecycle_log_status_ck CHECK (status IN ('success','failed','started'))");
        DB::statement('ALTER TABLE mod_lifecycle_log ADD CONSTRAINT mod_lifecycle_log_actor_ck CHECK (char_length(trim(actor_type)) > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('mod_lifecycle_log');
    }
};
