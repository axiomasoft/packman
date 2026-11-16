<?php

declare(strict_types=1);

namespace Packman\DevTools\Internal\Gates;

use Packman\Attributes\Internal;
use PhpParser\Node;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Shared file walker and AST helper reused across devtools quality gates.
 */
#[Internal]
final class GateFileWalker
{
    private readonly Parser $parser;

    public function __construct()
    {
        $this->parser = (new ParserFactory)->createForHostVersion();
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

    /** @return ?list<Node\Stmt> */
    public function parseAst(string $phpFile): ?array
    {
        $code = file_get_contents($phpFile);

        if (! is_string($code) || $code === '') {
            return null;
        }

        $ast = $this->parser->parse($code);

        return $ast !== null ? array_values($ast) : null;
    }

    /** @return list<string> */
    public function importedSymbols(string $phpFile): array
    {
        $ast = $this->parseAst($phpFile);

        if ($ast === null) {
            return [];
        }

        $imports = [];
        $this->collectUseNodes($ast, $imports);

        return array_values(array_unique($imports));
    }

    /**
     * @param  array<Node\Stmt>  $stmts
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
}
