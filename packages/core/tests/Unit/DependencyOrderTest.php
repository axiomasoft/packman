<?php

declare(strict_types=1);

use Packman\Foundation\DependencyOrder;

it('honors declared tie order, cycles, missing prerequisites and duplicate edges', function (): void {
    $result = DependencyOrder::resolve([
        'early' => ['base', 'base'],
        'base' => [],
        'other' => [],
        'cycle' => ['loop'],
        'loop' => ['cycle'],
        'downstream' => ['loop'],
        'missing' => ['external'],
    ]);

    expect($result->ordered)->toBe(['base', 'early', 'other'])
        ->and($result->blocked)->toBe(['cycle', 'loop', 'downstream', 'missing']);
});
