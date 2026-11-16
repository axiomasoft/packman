<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

// Intentionally always fails — AC-7 fixture (migration K of N).
return new class extends Migration
{
    public function up(): never
    {
        throw new RuntimeException('boom: migration K intentionally fails (AC-7 fixture).');
    }

    public function down(): void {}
};
