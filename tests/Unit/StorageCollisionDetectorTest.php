<?php

declare(strict_types=1);

use Packman\Schema\Internal\Services\StorageCollisionDetector;

it('reports tables claimed twice on the same physical database, ignoring case', function (): void {
    $eu = 'pgsql://db.eu:5432/chat?schema=tenant_eu';

    expect(StorageCollisionDetector::problems([
        ['identity' => $eu, 'table' => 'x_pmn_channels', 'owner' => 'eu/core'],
        ['identity' => $eu, 'table' => 'X_PMN_CHANNELS', 'owner' => 'eu2/core'],
        ['identity' => 'sqlite::memory:@app', 'table' => 'x_pmn_channels', 'owner' => 'default/core'],
    ]))->toBe([
        "table [X_PMN_CHANNELS] on [{$eu}] is claimed by [eu/core] and [eu2/core]; give one space another tablePrefix()/connection or map the table",
    ]);
});

it('reports nothing for distinct tables', function (): void {
    expect(StorageCollisionDetector::problems([
        ['identity' => 'a', 'table' => 't1', 'owner' => 'x/core'],
        ['identity' => 'a', 'table' => 't2', 'owner' => 'y/core'],
    ]))->toBe([]);
});
