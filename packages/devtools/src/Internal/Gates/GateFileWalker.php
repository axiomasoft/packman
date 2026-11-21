<?php

declare(strict_types=1);

namespace Packman\DevTools\Internal\Gates;

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
 * Each path plus source hash is parsed once. Callers receive a clone so later mutations stay local.
 */
#[Internal]
final class GateFileWalker
{
    private readonly Parser $parser;

    /**
     * Canonical, unresolved statement lists. Null means the parser recovered nothing.
     *
     * @var array<string, list<Stmt>|null>
     */
    private static array $statements = [];

    /** @var array<string, list<string>> */
    private static array $imports = [];

    /** @var array<string, list<string>> */
    private static array $internals = [];

    /** @var array<string, list<Stmt>|null> */
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

        return $this->clonedStatements($source['key'], $source['code']);
    }

    /**
     * Name-resolved statements. The resolved tree is cached once per source hash
     * and every caller receives its own clone.
     *
     * @return ?list<Stmt>
     */
    public function resolvedAst(string $phpFile): ?array
    {
        $source = $this->source($phpFile);

        if ($source === null) {
            return null;
        }

        $resolved = $this->canonicalResolved($source['key'], $source['code']);

        return $resolved === null ? null : AstCloner::cloneStatements($resolved);
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

        $ast = $this->clonedStatements($key, $source['code']);

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

        $ast = $this->canonicalResolved($key, $source['code']);

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
    private function canonicalResolved(string $key, string $code): ?array
    {
        if (! array_key_exists($key, self::$resolved)) {
            $ast = $this->clonedStatements($key, $code);

            if ($ast === null) {
                self::$resolved[$key] = null;
            } else {
                $resolvedNodes = [];

                foreach (new NodeTraverser(new NameResolver)->traverse($ast) as $node) {
                    if ($node instanceof Stmt) {
                        $resolvedNodes[] = $node;
                    }
                }

                self::$resolved[$key] = $resolvedNodes;
            }
        }

        return self::$resolved[$key];
    }

    /**
     * @return list<Stmt>|null
     */
    private function canonicalStatements(string $key, string $code): ?array
    {
        if (! array_key_exists($key, self::$statements)) {
            $parsed = $this->parser->parse($code);
            self::$statements[$key] = $parsed === null ? null : array_values($parsed);
        }

        return self::$statements[$key];
    }

    /**
     * @return list<Stmt>|null
     */
    private function clonedStatements(string $key, string $code): ?array
    {
        $canonical = $this->canonicalStatements($key, $code);

        if ($canonical === null) {
            return null;
        }

        return AstCloner::cloneStatements($canonical);
    }
}
