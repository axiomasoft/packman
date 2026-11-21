<?php

declare(strict_types=1);

use Packman\DevTools\Scaffold\GeneratedFile;
use Packman\DevTools\Scaffold\ScaffoldRunner;

it('refuses a scaffold file that resolves outside the base path', function (): void {
    $base = sys_get_temp_dir().'/packman-scaffold-'.bin2hex(random_bytes(4));
    mkdir($base);
    $escape = dirname($base).'/escape.txt';

    try {
        expect(fn () => (new ScaffoldRunner)->run($base, static fn (): array => [
            new GeneratedFile('../escape.txt', 'escaped'),
        ]))->toThrow(RuntimeException::class, 'escapes the base path');

        expect(is_file($escape))->toBeFalse();
    } finally {
        if (is_file($escape)) {
            unlink($escape);
        }

        if (is_dir($base)) {
            rmdir($base);
        }
    }
});
