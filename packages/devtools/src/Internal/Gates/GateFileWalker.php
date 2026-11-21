<?php

declare(strict_types=1);

namespace Packman\DevTools\Internal\Gates;

use LogicException;
use Packman\Attributes\Internal;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Shared file walker and AST helper reused across devtools quality gates.
 *
 * Cache compressed copies of the trees when zlib is available. Decoding creates
 * fresh nodes so caller mutations never affect subsequent reads.
 */
#[Internal]
final class GateFileWalker
{
    private readonly Parser $parser;

    /**
     * Compressed, unresolved statement lists. Null means the parser produced no statements.
     *
     * @var array<string, string|null>
     */
    private static array $statements = [];

    /** @var array<string, list<string>> */
    private static array $imports = [];

    /** @var array<string, list<string>> */
    private static array $internals = [];

    /** @var array<string, string|null> */
    private static array $resolved = [];

    public function __construct()
    {
        $this->parser = (new ParserFactory)->createForHostVersion();
    }

    /** Clears parsed trees and cached symbol lists. */
    public static function flushCaches(): void
    {
        self::$statements = [];
        self::$imports = [];
        self::$internals = [];
        self::$resolved = [];
    }

    /** @return list<string> */
    public function phpFiles(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files, SORT_STRING);

        return $files;
    }

    public function relativePath(string $rootPath, string $fullPath): string
    {
        $prefix = rtrim($rootPath, '/').'/';

        return str_starts_with($fullPath, $prefix)
            ? substr($fullPath, strlen($prefix))
            : $fullPath;
    }

    /** @return ?list<Stmt> */
    public function parseAst(string $phpFile): ?array
    {
        $source = $this->source($phpFile);

        if ($source === null) {
            return null;
        }

        return $this->statements($source['key'], $source['code']);
    }

    /**
     * Name-resolved statements. The resolved tree is cached once per source hash
     * and every caller receives its own copy.
     *
     * @return ?list<Stmt>
     */
    public function resolvedAst(string $phpFile): ?array
    {
        $source = $this->source($phpFile);

        if ($source === null) {
            return null;
        }

        return $this->resolvedStatements($source['key'], $source['code']);
    }

    /** @return list<string> */
    public function importedSymbols(string $phpFile): array
    {
        $source = $this->source($phpFile);

        if ($source === null) {
            return [];
        }

        $key = $source['key'];

        if (array_key_exists($key, self::$imports)) {
            return self::$imports[$key];
        }

        $ast = $this->statements($key, $source['code']);

        if ($ast === null) {
            self::$imports[$key] = [];

            return [];
        }

        $imports = [];
        $this->collectUseNodes($ast, $imports);
        self::$imports[$key] = array_values(array_unique($imports));

        return self::$imports[$key];
    }

    /** @return list<string> */
    public function internalSymbols(string $phpFile): array
    {
        $source = $this->source($phpFile);

        if ($source === null) {
            return [];
        }

        $key = $source['key'];

        if (array_key_exists($key, self::$internals)) {
            return self::$internals[$key];
        }

        $ast = $this->resolvedStatements($key, $source['code']);

        if ($ast === null) {
            self::$internals[$key] = [];

            return [];
        }

        $types = (new NodeFinder)->findInstanceOf($ast, ClassLike::class);
        $internal = [];

        foreach ($types as $type) {
            if ($type->namespacedName === null) {
                continue;
            }

            foreach ($type->attrGroups as $group) {
                foreach ($group->attrs as $attribute) {
                    if ($attribute->name->toString() === Internal::class) {
                        $internal[] = $type->namespacedName->toString();
                    }
                }
            }
        }

        self::$internals[$key] = $internal;

        return self::$internals[$key];
    }

    /**
     * @param  array<Stmt>  $stmts
     * @param  list<string>  $imports
     */
    private function collectUseNodes(array $stmts, array &$imports): void
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Namespace_) {
                $this->collectUseNodes($stmt->stmts, $imports);

                continue;
            }

            if ($stmt instanceof Use_ && $stmt->type === Use_::TYPE_NORMAL) {
                foreach ($stmt->uses as $use) {
                    $imports[] = $use->name->toString();
                }

                continue;
            }

            if ($stmt instanceof GroupUse && $stmt->type === Use_::TYPE_NORMAL) {
                $prefix = $stmt->prefix->toString();
                foreach ($stmt->uses as $use) {
                    if ($use->type === Use_::TYPE_NORMAL || $use->type === Use_::TYPE_UNKNOWN) {
                        $imports[] = $prefix.'\\'.$use->name->toString();
                    }
                }
            }
        }
    }

    /**
     * Empty and unreadable files are not cached, so a later write is parsed.
     *
     * @return array{key: string, code: string}|null
     */
    private function source(string $phpFile): ?array
    {
        $code = file_get_contents($phpFile);

        if (! is_string($code) || $code === '') {
            return null;
        }

        return [
            'key' => self::cacheKey($phpFile, $code),
            'code' => $code,
        ];
    }

    private static function cacheKey(string $phpFile, string $contents): string
    {
        $path = realpath($phpFile);

        if ($path === false) {
            $path = $phpFile;
        }

        return $path."\0".hash('sha256', $contents);
    }

    /**
     * @return list<Stmt>|null
     */
    private function resolvedStatements(string $key, string $code): ?array
    {
        if (array_key_exists($key, self::$resolved)) {
            return self::decodeStatements(self::$resolved[$key]);
        }

        $ast = $this->statements($key, $code);

        if ($ast === null) {
            self::$resolved[$key] = null;

            return null;
        }

        $resolved = [];
        foreach (new NodeTraverser(new NameResolver)->traverse($ast) as $node) {
            if ($node instanceof Stmt) {
                $resolved[] = $node;
            }
        }

        self::cacheStatements(self::$resolved, $key, $resolved);

        return $resolved;
    }

    /**
     * @return list<Stmt>|null
     */
    private function statements(string $key, string $code): ?array
    {
        if (array_key_exists($key, self::$statements)) {
            return self::decodeStatements(self::$statements[$key]);
        }

        $parsed = $this->parser->parse($code);
        $statements = $parsed === null ? null : array_values($parsed);
        self::cacheStatements(self::$statements, $key, $statements);

        return $statements;
    }

    /**
     * @param  array<string, string|null>  $cache
     * @param  list<Stmt>|null  $statements
     */
    private static function cacheStatements(array &$cache, string $key, ?array $statements): void
    {
        if ($statements === null) {
            $cache[$key] = null;

            return;
        }

        if (function_exists('gzdeflate')) {
            $compressed = gzdeflate(serialize($statements), 1);

            if ($compressed !== false) {
                $cache[$key] = $compressed;
            }
        }
    }

    /**
     * @return list<Stmt>|null
     */
    private static function decodeStatements(?string $compressed): ?array
    {
        if ($compressed === null) {
            return null;
        }

        // Only statement lists serialized by this process reach this cache.
        $serialized = gzinflate($compressed);
        $statements = $serialized === false ? false : unserialize($serialized);

        if (! is_array($statements)) {
            throw new LogicException('Unable to read the cached PHP statements.');
        }

        $decoded = [];
        foreach ($statements as $statement) {
            if (! $statement instanceof Stmt) {
                throw new LogicException('Unable to read a cached PHP statement.');
            }

            $decoded[] = $statement;
        }

        return $decoded;
    }
}
