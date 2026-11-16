<?php

declare(strict_types=1);

namespace Packman\Schema\Tests\Fixtures;

use Illuminate\Database\Schema\Blueprint;
use Packman\Schema\Contracts\SchemaStep;
use Packman\Schema\SpaceSchema;

final class FixtureStep implements SchemaStep
{
    public function up(SpaceSchema $schema): void
    {
        $schema->create('fixture', static function (Blueprint $table) use ($schema): void {
            $schema->key($table);
            $table->string('label');
        });
    }

    public function down(SpaceSchema $schema): void
    {
        $schema->drop('fixture');
    }
}
