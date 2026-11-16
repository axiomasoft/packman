<?php

declare(strict_types=1);

use Packman\Modules\Contracts\RecordsRegistrar;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sbf_ok', function (Blueprint $table): void {
            $table->id();
        });

        resolve(RecordsRegistrar::class)->claim('packman/stub-failing', 'table', 'sbf_ok');
    }

    public function down(): void
    {
        Schema::dropIfExists('sbf_ok');
    }
};
