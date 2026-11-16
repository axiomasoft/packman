<?php

declare(strict_types=1);

namespace Packman\Panels\SavedViews\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Panels\Internal\Configuration\PanelsOptions;
use Packman\Panels\SavedViews\Contracts\ViewAccessPolicy;
use Packman\Panels\SavedViews\Contracts\ViewSchemaProvider;
use Packman\Panels\SavedViews\Internal\SqlSavedViewRepository;
use Packman\Panels\SavedViews\Internal\ViewConfigValidator;
use Psr\Clock\ClockInterface;

/** Builds the saved-view repository on the database manager of the current application. */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class SavedViewRepositoryFactory
{
    public function make(Application $app): SqlSavedViewRepository
    {
        /** @var DatabaseManager $database */
        $database = $app->make('db');

        return new SqlSavedViewRepository(
            schemas: $app->make(ViewSchemaProvider::class),
            policy: $app->make(ViewAccessPolicy::class),
            validator: $app->make(ViewConfigValidator::class),
            options: $app->make(PanelsOptions::class),
            database: $database,
            clock: $app->make(ClockInterface::class),
        );
    }
}
