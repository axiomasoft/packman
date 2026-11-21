<?php

declare(strict_types=1);

use Packman\DevTools\Internal\Gates\GateFileWalker;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Nop;
use PhpParser\Node\Stmt\Use_;

beforeEach(function (): void {
    GateFileWalker::flushCaches();
});

afterEach(function (): void {
    GateFileWalker::flushCaches();
});

it('returns the same imported symbols from separate walkers', function (): void {
    gateWalkerCacheWithFile(gateWalkerCacheSecretSource(), function (string $path): void {
        $first = new GateFileWalker;
        $second = new GateFileWalker;

        expect($first->importedSymbols($path))
            ->toBe($second->importedSymbols($path))
            ->toBe(['Packman\\Attributes\\Internal']);
    });
});

it('keeps later parses stable after a caller mutates a returned tree', function (): void {
    gateWalkerCacheWithFile(gateWalkerCacheSecretSource(), function (string $path): void {
        $walker = new GateFileWalker;
        $pristine = gateWalkerCacheAst($walker, $path);
        $first = gateWalkerCacheAst($walker, $path);
        $pristineNamespace = gateWalkerCacheNamespace($pristine);
        $pristineClass = gateWalkerCacheClass($pristine);
        $innerCount = count($pristineNamespace->stmts);
        $outerCount = count($pristine);

        expect($first[0])->not->toBe($pristine[0])
            ->and($pristineClass->name)->not->toBeNull()
            ->and(gateWalkerCacheClass($first)->name)->not->toBe($pristineClass->name);

        $namespace = gateWalkerCacheNamespace($first);
        $class = gateWalkerCacheClass($first);
        $use = gateWalkerCacheUse($namespace);

        expect($namespace->name)->not->toBeNull()
            ->and($class->name)->not->toBeNull();

        $namespace->name->name = 'Tampered';
        $namespace->stmts[] = new Nop;
        $use->uses[0]->name->name = 'Tampered\\Symbol';
        $class->name->name = 'Tampered';
        $class->attrGroups[0]->attrs[0]->name->name = 'TamperedAttribute';
        $argument = $class->attrGroups[0]->attrs[0]->args[0]->value;
        expect($argument)->toBeInstanceOf(String_::class);
        $argument->value = 'tampered';
        $first[] = new Nop;

        expect($pristineNamespace->stmts)->toHaveCount($innerCount)
            ->and($pristineClass->name->toString())->toBe('Secret')
            ->and(gateWalkerCacheUse($pristineNamespace)->uses[0]->name->toString())->toBe('Packman\\Attributes\\Internal');

        $reread = gateWalkerCacheAst($walker, $path);

        expect($reread)->toHaveCount($outerCount)
            ->and(gateWalkerCacheNamespace($reread)->name?->toString())->toBe('Demo')
            ->and(gateWalkerCacheNamespace($reread)->stmts)->toHaveCount($innerCount)
            ->and(gateWalkerCacheClass($reread)->name?->toString())->toBe('Secret')
            ->and(gateWalkerCacheClass($reread)->attrGroups[0]->attrs[0]->name->toString())->toBe('Internal')
            ->and(gateWalkerCacheClass($reread)->attrGroups[0]->attrs[0]->args[0]->value)->toBeInstanceOf(String_::class)
            ->and(gateWalkerCacheClass($reread)->attrGroups[0]->attrs[0]->args[0]->value->value)->toBe('hidden')
            ->and((new GateFileWalker)->importedSymbols($path))->toBe(['Packman\\Attributes\\Internal'])
            ->and((new GateFileWalker)->importedSymbols($path))->toBe(['Packman\\Attributes\\Internal']);
    });
});

it('does not let NameResolver poison the cached tree', function (): void {
    gateWalkerCacheWithFile(gateWalkerCacheSecretSource(), function (string $path): void {
        $walker = new GateFileWalker;

        expect($walker->internalSymbols($path))->toBe(['Demo\\Secret'])
            ->and($walker->internalSymbols($path))->toBe(['Demo\\Secret'])
            ->and((new GateFileWalker)->internalSymbols($path))->toBe(['Demo\\Secret']);

        $ast = gateWalkerCacheAst($walker, $path);
        $class = gateWalkerCacheClass($ast);
        $attribute = $class->attrGroups[0]->attrs[0]->name;

        expect(isset($class->namespacedName))->toBeFalse()
            ->and($attribute)->not->toBeInstanceOf(FullyQualified::class)
            ->and($attribute->toString())->toBe('Internal')
            ->and($class->name?->toString())->toBe('Secret')
            ->and(gateWalkerCacheUse(gateWalkerCacheNamespace($ast))->uses[0]->name->toString())->toBe('Packman\\Attributes\\Internal');
    });
});

it('notices rewritten contents even when mtime stays the same', function (): void {
    gateWalkerCacheWithFile(gateWalkerCacheSecretSource(), function (string $path): void {
        $walker = new GateFileWalker;

        expect($walker->internalSymbols($path))->toBe(['Demo\\Secret'])
            ->and($walker->importedSymbols($path))->toBe(['Packman\\Attributes\\Internal'])
            ->and($walker->internalSymbols($path))->toBe(['Demo\\Secret']);

        clearstatcache(true, $path);
        $mtime = filemtime($path);

        if (! is_int($mtime)) {
            throw new RuntimeException('Fixture mtime is unavailable.');
        }

        expect(file_put_contents($path, gateWalkerCacheRenamedSource()))->not->toBeFalse()
            ->and(touch($path, $mtime))->toBeTrue();
        clearstatcache(true, $path);

        expect(filemtime($path))->toBe($mtime)
            ->and((new GateFileWalker)->internalSymbols($path))->toBe(['Demo\\Renamed'])
            ->and((new GateFileWalker)->importedSymbols($path))->toBe([
                'Packman\\Attributes\\Internal',
                'Packman\\Attributes\\Api',
            ])
            ->and(gateWalkerCacheClass(gateWalkerCacheAst(new GateFileWalker, $path))->name?->toString())->toBe('Renamed');
    });
});

it('reuses a name-resolved tree without sharing it or poisoning the unresolved parse', function (): void {
    gateWalkerCacheWithFile(gateWalkerCacheSecretSource(), function (string $path): void {
        $walker = new GateFileWalker;
        $resolved = $walker->resolvedAst($path);

        expect($resolved)->not->toBeNull();
        assert(is_array($resolved));

        $class = gateWalkerCacheClass($resolved);

        expect(isset($class->namespacedName))->toBeTrue()
            ->and($class->namespacedName?->toString())->toBe('Demo\\Secret');

        if ($class->name !== null) {
            $class->name->name = 'Tampered';
        }

        $again = (new GateFileWalker)->resolvedAst($path);

        expect($again)->not->toBeNull();
        assert(is_array($again));

        expect(gateWalkerCacheClass($again)->name?->toString())->toBe('Secret')
            ->and(gateWalkerCacheClass($again)->namespacedName?->toString())->toBe('Demo\\Secret')
            ->and(isset(gateWalkerCacheClass(gateWalkerCacheAst($walker, $path))->namespacedName))->toBeFalse();
    });
});

it('does not reuse a null parse after an empty file gains contents', function (): void {
    gateWalkerCacheWithFile('', function (string $path): void {
        expect((new GateFileWalker)->parseAst($path))->toBeNull()
            ->and((new GateFileWalker)->importedSymbols($path))->toBe([])
            ->and((new GateFileWalker)->internalSymbols($path))->toBe([]);

        expect(file_put_contents($path, gateWalkerCacheSecretSource()))->not->toBeFalse();

        expect((new GateFileWalker)->parseAst($path))->not->toBeNull()
            ->and((new GateFileWalker)->internalSymbols($path))->toBe(['Demo\\Secret']);
    });
});

function gateWalkerCacheSecretSource(): string
{
    return <<<'PHP'
<?php

declare(strict_types=1);

namespace Demo;

use Packman\Attributes\Internal;

#[Internal('hidden')]
final class Secret {}

PHP;
}

function gateWalkerCacheRenamedSource(): string
{
    return <<<'PHP'
<?php

declare(strict_types=1);

namespace Demo;

use Packman\Attributes\Internal;
use Packman\Attributes\Api;

#[Internal('hidden')]
final class Renamed {}

PHP;
}

/**
 * @param  Closure(string): void  $assert
 */
function gateWalkerCacheWithFile(string $source, Closure $assert): void
{
    $directory = sys_get_temp_dir().'/packman-gate-walker-'.bin2hex(random_bytes(8));

    if (! mkdir($directory, 0775, true) && ! is_dir($directory)) {
        throw new RuntimeException("Unable to create [{$directory}].");
    }

    $path = $directory.'/Subject.php';
    file_put_contents($path, $source);

    try {
        $assert($path);
    } finally {
        if (is_file($path)) {
            unlink($path);
        }

        rmdir($directory);
    }
}

/**
 * @return list<Stmt>
 */
function gateWalkerCacheAst(GateFileWalker $walker, string $path): array
{
    $ast = $walker->parseAst($path);

    if ($ast === null) {
        throw new RuntimeException("Unable to parse [{$path}].");
    }

    return $ast;
}

/**
 * @param  list<Stmt>  $statements
 */
function gateWalkerCacheNamespace(array $statements): Namespace_
{
    foreach ($statements as $statement) {
        if ($statement instanceof Namespace_) {
            return $statement;
        }
    }

    throw new RuntimeException('Namespace missing from parsed sample.');
}

/**
 * @param  list<Stmt>  $statements
 */
function gateWalkerCacheClass(array $statements): Class_
{
    foreach (gateWalkerCacheNamespace($statements)->stmts as $statement) {
        if ($statement instanceof Class_) {
            return $statement;
        }
    }

    throw new RuntimeException('Class missing from parsed sample.');
}

function gateWalkerCacheUse(Namespace_ $namespace): Use_
{
    foreach ($namespace->stmts as $statement) {
        if ($statement instanceof Use_) {
            return $statement;
        }
    }

    throw new RuntimeException('Use statement missing from parsed sample.');
}
