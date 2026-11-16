<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// No hand-written claim(): auto-claimed from the schema diff (D41).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sba_notes', function (Blueprint $table): void {
            $table->id();
            $table->text('body');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sba_notes');
    }
};
