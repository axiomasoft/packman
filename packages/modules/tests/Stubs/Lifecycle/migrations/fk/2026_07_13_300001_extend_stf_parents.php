<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Extends the BASE module's already-existing sba_widgets with a column + index.
// The wrapper claims these as `column`/`index` owned by stub-fk (not stub-base),
// so purging stub-fk must drop them WITHOUT dropping sba_widgets — the cross-
// module claim types purge ignored before D41.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sba_widgets', function (Blueprint $table): void {
            $table->string('stf_extra')->nullable();
            $table->index('stf_extra', 'stf_widgets_extra_ix');
        });
    }

    public function down(): void
    {
        Schema::table('sba_widgets', function (Blueprint $table): void {
            $table->dropIndex('stf_widgets_extra_ix');
            $table->dropColumn('stf_extra');
        });
    }
};
