<?php

declare(strict_types=1);

use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Schema\Internal\Compiler\ScopedOwnershipRegistry;
use Packman\Schema\Laravel\SchemaExtensions;

it('checks conflicting claims within their prefix and requires a prefix for ambiguous unqualified names', function (): void {
    $ownership = new ScopedOwnershipRegistry;
    $shop = SchemaExtensions::ownershipFor($ownership, 'shop_');
    $chat = SchemaExtensions::ownershipFor($ownership, 'chat_');
    $shop->declare('orders', ['channel']);
    expect($ownership->ownerOf('channels'))->toBe('orders');
    $chat->declare('messages', ['channel']);
    $ownership->declare('host', ['x_host']);

    expect($shop->ownerOf('channels'))->toBe('orders')
        ->and($chat->ownerOf('channels'))->toBe('messages')
        ->and($shop->ownerOf('x_host_outbox'))->toBe('host')
        ->and(fn () => $ownership->ownerOf('channels'))->toThrow(InvalidConfiguration::class, 'multiple prefixes')
        ->and(fn () => $shop->declare('other', ['channel']))->toThrow(InvalidConfiguration::class, 'already owned');
});
