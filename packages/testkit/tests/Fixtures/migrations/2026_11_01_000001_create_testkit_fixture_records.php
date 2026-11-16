<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('testkit_fixture_records')) {
            Schema::create('testkit_fixture_records', static function (Blueprint $table): void {
                $table->id();
                $table->string('name');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('testkit_fixture_records');
    }
};
