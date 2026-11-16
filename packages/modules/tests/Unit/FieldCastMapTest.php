<?php

declare(strict_types=1);

use Packman\Modules\Internal\Compiler\FieldCastMap;

it('maps package casts with arguments and leaves unknown casts to the compiler', function (): void {
    $casts = new FieldCastMap;
    $casts->register(stdClass::class, 'money');

    expect($casts->typeFor(stdClass::class))->toBe('money')
        ->and($casts->typeFor(stdClass::class.':USD'))->toBe('money')
        ->and($casts->typeFor('unknown'))->toBeNull();
});
