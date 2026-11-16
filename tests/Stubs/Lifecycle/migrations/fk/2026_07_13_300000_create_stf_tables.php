<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Parent BEFORE child in the SAME migration: their pg_class oids encode
// creation order, so the auto-claim wrapper assigns the child a higher `seq`
// and purge drops it first — FK-safe (Blocker A3). No hand-written claim().
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stf_parents', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        Schema::create('stf_children', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->constrained('stf_parents');
            $table->string('label');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stf_children');
        Schema::dropIfExists('stf_parents');
    }
};
