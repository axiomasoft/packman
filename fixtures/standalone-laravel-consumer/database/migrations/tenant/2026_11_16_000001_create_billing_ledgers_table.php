<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Packman\Schema\Laravel\PackmanMigration;

return new class extends PackmanMigration
{
    public function up(): void
    {
        $this->create('billing_ledgers', function (Blueprint $table): void {
            $this->uuidPrimary($table);
            $table->string('reference', 64);
            $this->money($table, 'total');
            $this->timestampsTz($table);
            $this->unique($table, 'reference');
        });
    }

    public function down(): void
    {
        $this->drop('billing_ledgers');
    }
};
