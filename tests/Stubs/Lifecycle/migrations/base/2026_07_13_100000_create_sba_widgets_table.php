<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// No hand-written claim(): the lifecycle's migration wrapper auto-claims
// sba_widgets from the schema diff (D41).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sba_widgets', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sba_widgets');
    }
};
