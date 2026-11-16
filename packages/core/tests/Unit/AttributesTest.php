<?php

declare(strict_types=1);

use Packman\Attributes\Api;
use Packman\Attributes\Internal;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

it('Attributes expose immutable metadata and backed kind roundtrips', function (): void {
    expect(new Api)->since->toBe('1.0');
    expect(new Spi(SpiKind::Implement))->kind->toBe(SpiKind::Implement);
    expect(SpiKind::from('call'))->toBe(SpiKind::Call);
    expect(new Internal('helper'))->reason->toBe('helper');

    foreach ([Api::class, Spi::class] as $class) {
        $reflection = new ReflectionClass($class);
        expect($reflection->isFinal())->toBeTrue()->and($reflection->isReadOnly())->toBeTrue();
        expect($reflection->getAttributes(Attribute::class)[0]->newInstance()->flags)->toBe(Attribute::TARGET_CLASS);
        expect($reflection->getAttributes(Api::class)[0]->newInstance()->since)->toBe('1.0');
    }
});

it('Attributes reject a method target at instantiation', function (): void {
    $subject = new class
    {
        #[Api]
        public function method(): void {}
    };
    new ReflectionMethod($subject, 'method')->getAttributes()[0]->newInstance();
})->throws(Error::class, 'cannot target method');
