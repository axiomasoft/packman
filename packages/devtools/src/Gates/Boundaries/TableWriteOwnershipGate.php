<?php

declare(strict_types=1);

namespace Packman\DevTools\Gates\Boundaries;

use Packman\Attributes\Api;
use Packman\DevTools\Contracts\Gates\QualityGate;
use Packman\DevTools\Data\Gates\GateContext;
use Packman\DevTools\Data\Gates\GateResult;
use Packman\DevTools\Internal\Gates\GateFileWalker;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;

/**
 * Forbids direct mutating writes to sys_*, mod_*, or migrations tables outside core infrastructure packages.
 */
#[Api(since: '1.0')]
final readonly class TableWriteOwnershipGate implements QualityGate
{
    /** @var list<string> */
    private const array MUTATING_METHODS = [
        'insert',
        'insertgetid',
        'insertorignore',
        'update',
        'updateorinsert',
        'upsert',
        'delete',
        'truncate',
    ];

    /** @var list<string> */
    private const array SYSTEM_OWNER_PACKAGES = [
        'axiomasoft/packman-schema',
        'axiomasoft/packman-modules',
    ];

    private GateFileWalker $walker;

    public function __construct()
    {
        $this->walker = new GateFileWalker;
    }

    public function id(): string
    {
        return 'table-write-ownership';
    }

    public function category(): string
    {
        return 'boundaries';
    }

    public function description(): string
    {
        return 'Blocks direct DB::table() writes against sys_*/mod_*/migrations outside schema/modules owner packages.';
    }

    public function run(GateContext $context): GateResult
    {
        $violations = [];
        $finder = new NodeFinder;

        foreach ($context->config->packages as $package) {
            if ($package->devOnly || in_array($package->name, self::SYSTEM_OWNER_PACKAGES, true)) {
                continue;
            }

            $srcDir = $context->packageDirectory($package).'/src';
            foreach ($this->walker->phpFiles($srcDir) as $file) {
                $ast = $this->walker->parseAst($file);

                if ($ast === null) {
                    continue;
                }

                $rel = $this->walker->relativePath($context->rootPath, $file);
                /** @var list<MethodCall> $calls */
                $calls = $finder->findInstanceOf($ast, MethodCall::class);

                foreach ($calls as $call) {
                    if (! $call->name instanceof Identifier) {
                        continue;
                    }

                    if (! in_array(strtolower($call->name->toString()), self::MUTATING_METHODS, true)) {
                        continue;
                    }

                    $tableName = $this->extractTargetTable($call->var);

                    if ($tableName === null) {
                        continue;
                    }

                    if ($this->isProtectedSystemTable($tableName)) {
                        $violations[] = sprintf(
                            '[%s] Forbidden direct write ->%s() on protected system table "%s" in %s:%d.',
                            $package->name,
                            $call->name->toString(),
                            $tableName,
                            $rel,
                            $call->getStartLine(),
                        );
                    }
                }
            }
        }

        return $violations === []
            ? GateResult::pass($this->id(), $this->category(), ['No unauthorized writes to protected system tables detected.'])
            : GateResult::fail($this->id(), $this->category(), $violations);
    }

    private function extractTargetTable(mixed $expr): ?string
    {
        $cursor = $expr;
        while ($cursor instanceof MethodCall) {
            if ($cursor->name instanceof Identifier && strtolower($cursor->name->toString()) === 'table') {
                $firstArg = $cursor->args[0] ?? null;

                return ($firstArg instanceof Arg && $firstArg->value instanceof String_) ? $firstArg->value->value : null;
            }

            $cursor = $cursor->var;
        }

        if ($cursor instanceof StaticCall && $cursor->class instanceof Name && $cursor->name instanceof Identifier) {
            if ($cursor->class->getLast() === 'DB' && strtolower($cursor->name->toString()) === 'table') {
                $firstArg = $cursor->args[0] ?? null;

                return ($firstArg instanceof Arg && $firstArg->value instanceof String_) ? $firstArg->value->value : null;
            }
        }

        return null;
    }

    private function isProtectedSystemTable(string $table): bool
    {
        return $table === 'migrations'
            || str_starts_with($table, 'sys_')
            || str_starts_with($table, 'mod_');
    }
}
