<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Logical module ownership keys deliberately have no foreign key to mod_modules.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mod_records', function (Blueprint $table): void {
            $table->string('module_name', 190);
            $table->string('record_type', 40);
            $table->string('reference', 255);
            $table->jsonb('meta')->default('{}');
            $table->timestampTz('created_at', precision: 6)->useCurrent();
            $table->timestampTz('updated_at', precision: 6)->useCurrent();

            $table->primary(['module_name', 'record_type', 'reference'], 'mod_records_pk');
        });

        Schema::getConnection()->statement('ALTER TABLE mod_records RENAME CONSTRAINT mod_records_pkey TO mod_records_pk');

        // Insertion order determines reverse, foreign-key-safe purge order.
        DB::statement('ALTER TABLE mod_records ADD COLUMN seq bigserial');

        DB::statement('CREATE INDEX mod_records_module_seq_ix ON mod_records (module_name, seq DESC)');
        DB::statement("ALTER TABLE mod_records ADD CONSTRAINT mod_records_type_ck CHECK (record_type IN ('table','column','index','role','permission','menu','setting','seed','blueprint','feature_flag','other'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('mod_records');
    }
};
