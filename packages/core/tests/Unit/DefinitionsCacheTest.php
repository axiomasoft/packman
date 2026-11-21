<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Packman\Config\Data\CompileContext;
use Packman\Config\Internal\CompiledSections;
use Packman\Config\Internal\DefinitionsCache;

function cacheTestContext(string $env = 'testing'): CompileContext
{
    return new CompileContext($env, 'sqlite', ['sqlite'], null, false);
}

it('writes and hydrates definitions only for the same fingerprint and cache format', function (): void {
    $dir = sys_get_temp_dir().'/packman-cache-'.bin2hex(random_bytes(8));
    $cache = new DefinitionsCache($dir.'/packman.php');
    $definitions = new CompiledSections([], [], []);

    try {
        expect($cache->read('same'))->toBeNull();
        $cache->write($definitions, 'same');
        expect($cache->read('same'))->toEqual($definitions)->and($cache->read('other'))->toBeNull();
        file_put_contents($cache->path(), '<?php return ["format" => 0, "fingerprint" => "same"];');
        expect($cache->read('same'))->toBeNull();
        $cache->clear();
        expect(is_file($cache->path()))->toBeFalse();
    } finally {
        $cache->clear();
        @rmdir($dir);
    }
});

it('fingerprints associative key and section order independently but retains list order, values and context', function (): void {
    $a = ['packman' => ['a' => 1, 'b' => 2, 'list' => [1, 2]]];
    $b = ['packman' => ['list' => [1, 2], 'b' => 2, 'a' => 1]];
    $fp = DefinitionsCache::fingerprint($a, [stdClass::class, DateTimeImmutable::class], cacheTestContext());

    expect(DefinitionsCache::fingerprint($b, [DateTimeImmutable::class, stdClass::class], cacheTestContext()))->toBe($fp)
        ->and(DefinitionsCache::fingerprint(['packman' => ['a' => 2, 'b' => 2, 'list' => [1, 2]]], [stdClass::class, DateTimeImmutable::class], cacheTestContext()))->not->toBe($fp)
        ->and(DefinitionsCache::fingerprint($a, [stdClass::class, DateTimeImmutable::class], cacheTestContext('production')))->not->toBe($fp)
        ->and(DefinitionsCache::fingerprint(['packman' => ['a' => 1, 'b' => 2, 'list' => [2, 1]]], [stdClass::class, DateTimeImmutable::class], cacheTestContext()))->not->toBe($fp);
});

it('refuses closures and objects in raw configuration instead of fingerprinting them as empty maps', function (mixed $value): void {
    DefinitionsCache::fingerprint(['packman' => ['nested' => ['value' => $value]]], [], cacheTestContext());
})->with([fn (): array => [static fn (): int => 1], fn (): array => [new stdClass]])->throws(LogicException::class, 'not config-cache safe');

it('adds schema component inputs to the fingerprint and exposes cache format version', function (): void {
    $roots = ['packman' => ['a' => 1]];
    $base = DefinitionsCache::fingerprint($roots, [stdClass::class], cacheTestContext());
    $withSchema = DefinitionsCache::fingerprint($roots, [stdClass::class], cacheTestContext(), [['id' => 'fixture', 'sources' => ['A' => 'h1']]]);

    expect(DefinitionsCache::FORMAT)->toBe(7)
        ->and($withSchema)->not->toBe($base)
        ->and(DefinitionsCache::fingerprint($roots, [stdClass::class], cacheTestContext(), [['id' => 'fixture', 'sources' => ['A' => 'h2']]]))->not->toBe($withSchema)
        ->and(DefinitionsCache::fingerprint($roots, [stdClass::class], cacheTestContext(), []))->toBe($base);
});

it('fingerprints connection identities and prefixes', function (): void {
    $base = DefinitionsCache::fingerprint([], [], cacheTestContext());
    $moved = new CompileContext('testing', 'sqlite', ['sqlite'], null, false, connectionIdentities: ['sqlite' => 'sqlite::memory:@sqlite']);
    $prefixed = new CompileContext('testing', 'sqlite', ['sqlite'], null, false, connectionPrefixes: ['sqlite' => 'p_']);

    expect(DefinitionsCache::fingerprint([], [], $moved))->not->toBe($base)
        ->and(DefinitionsCache::fingerprint([], [], $prefixed))->not->toBe($base);
});

it('writes the cache readable by other users according to the umask', function (): void {
    $dir = sys_get_temp_dir().'/packman-cache-'.bin2hex(random_bytes(8));
    $cache = new DefinitionsCache($dir.'/packman.php');
    $previous = umask(0022);

    try {
        $cache->write(new CompiledSections([], [], []), 'same');
        expect(fileperms($cache->path()) & 0777)->toBe(0644);
    } finally {
        umask($previous);
        $cache->clear();
        @rmdir($dir);
    }
});

it('throws when the cache parent path is a file instead of a directory', function (): void {
    $dir = sys_get_temp_dir().'/packman-cache-'.bin2hex(random_bytes(8));
    mkdir($dir);
    $parent = $dir.'/parent';
    file_put_contents($parent, 'not a directory');
    $cache = new DefinitionsCache($parent.'/packman.php');

    try {
        expect(fn () => $cache->write(new CompiledSections([], [], []), 'same'))
            ->toThrow(LogicException::class, 'Cannot create '.$parent.'.');
    } finally {
        if (is_file($parent)) {
            unlink($parent);
        }
        @rmdir($dir);
    }
});

it('throws when the cache destination is a directory and leaves no temporary file', function (): void {
    // Destination is an existing directory — rename(tmpFile, dir) fails with
    // EISDIR, so the failed-rename branch is the one exercised.
    $dir = sys_get_temp_dir().'/packman-cache-'.bin2hex(random_bytes(8));
    $destination = $dir.'/packman.php';
    mkdir($dir);
    mkdir($destination);
    $cache = new DefinitionsCache($destination);

    try {
        expect(fn () => $cache->write(new CompiledSections([], [], []), 'same'))
            ->toThrow(LogicException::class, 'Cannot write '.$destination.'.');

        // packman* also matches the destination directory named packman.php.
        $leftover = array_values(array_filter(
            glob($dir.'/packman*') ?: [],
            static fn (string $path): bool => $path !== $destination,
        ));

        expect(is_dir($destination))->toBeTrue()
            ->and($leftover)->toBe([]);
    } finally {
        if (is_dir($destination)) {
            @rmdir($destination);
        }

        foreach (glob($dir.'/packman*') ?: [] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
});

it('ignores a matching cache that no longer decodes against the installed code', function (): void {
    $dir = sys_get_temp_dir().'/packman-cache-'.bin2hex(random_bytes(8));
    $cache = new DefinitionsCache($dir.'/packman.php');
    mkdir($dir);
    file_put_contents($cache->path(), '<?php return '.var_export(['format' => DefinitionsCache::FORMAT, 'fingerprint' => 'same', 'definitions' => ['@c' => 'Packman\\Missing\\Settings', 'a' => []]], true).';');

    try {
        expect($cache->read('same'))->toBeNull();
    } finally {
        $cache->clear();
        @rmdir($dir);
    }
});

it('fingerprints the installed packman package versions', function (): void {
    $versions = DefinitionsCache::codeVersions();

    expect($versions)->toHaveKey('axiomasoft/packman')
        ->and($versions['axiomasoft/packman'])->toBe(InstalledVersions::getVersion('axiomasoft/packman').'@'.InstalledVersions::getReference('axiomasoft/packman'));
});
