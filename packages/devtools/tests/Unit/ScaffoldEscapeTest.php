<?php

declare(strict_types=1);

use Packman\DevTools\Scaffold\GeneratedFile;
use Packman\DevTools\Scaffold\ScaffoldRunner;
use Packman\DevTools\Scaffold\StubStore;

it('refuses a scaffold file that resolves outside the base path', function (): void {
    $root = sys_get_temp_dir().'/packman-scaffold-'.bin2hex(random_bytes(8));
    $base = $root.'/base';
    mkdir($base, recursive: true);
    $escape = $root.'/escape.txt';

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

        rmdir($root);
    }
});

it('keeps scaffold writes and stub reads inside their own directories', function (): void {
    $root = sys_get_temp_dir().'/packman-scaffold-'.bin2hex(random_bytes(8));
    $base = $root.'/base';
    $stub = $root.'/stub.txt';
    $link = $base.'/outside';
    mkdir($base, recursive: true);
    file_put_contents($stub, 'outside');
    symlink($root, $link);

    try {
        expect(fn () => (new ScaffoldRunner)->run($base, static fn (): array => [
            new GeneratedFile('outside/write.txt', 'contents'),
        ]))->toThrow(RuntimeException::class, 'symbolic link')
            ->and(fn () => (new StubStore($base))->render('outside/stub.txt', []))
            ->toThrow(RuntimeException::class, 'inside the stub root');
    } finally {
        if (is_file($root.'/write.txt')) {
            unlink($root.'/write.txt');
        }

        unlink($link);
        unlink($stub);
        rmdir($base);
        rmdir($root);
    }
});
