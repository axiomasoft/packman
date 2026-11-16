<?php

declare(strict_types=1);

namespace Packman\DevTools\Gates\Boundaries;

use Packman\Attributes\Api;
use Packman\DevTools\Contracts\Gates\QualityGate;
use Packman\DevTools\Data\Gates\GateContext;
use Packman\DevTools\Data\Gates\GateResult;
use Packman\DevTools\Internal\Gates\GateFileWalker;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Global_;
use PhpParser\NodeFinder;

/**
 * Forbids raw superglobal access, global keyword usage, and ambient env() calls inside runtime src/.
 */
#[Api(since: '1.0')]
final readonly class RuntimeGlobalsGate implements QualityGate
{
    /** @var list<string> */
    private const array FORBIDDEN_SUPERGLOBALS = [
        '_GET',
        '_POST',
        '_REQUEST',
        '_COOKIE',
        '_FILES',
        '_SESSION',
        '_ENV',
        '_SERVER',
        'GLOBALS',
    ];

    /** @var list<string> */
    private const array DEV_HARNESS_EXEMPT = [
        '/Support/Database/',
        '/Support/Runtime/',
        '/Support/Fixtures/',
    ];

    private GateFileWalker $walker;

    public function __construct()
    {
        $this->walker = new GateFileWalker;
    }

    public function id(): string
    {
        return 'runtime-globals';
    }

    public function category(): string
    {
        return 'boundaries';
    }

    public function description(): string
    {
        return 'Forbids PHP superglobals, global keyword statements, and ambient env() in source files.';
    }

    public function run(GateContext $context): GateResult
    {
        $violations = [];
        $finder = new NodeFinder;

        foreach ($context->config->packages as $package) {
            $srcDir = $context->packageDirectory($package).'/src';
            foreach ($this->walker->phpFiles($srcDir) as $file) {
                if ($package->devOnly && $this->isDevHarnessExempt($file)) {
                    continue;
                }

                $ast = $this->walker->parseAst($file);

                if ($ast === null) {
                    continue;
                }

                $rel = $this->walker->relativePath($context->rootPath, $file);
                $isLaravelCliProbe = str_contains(str_replace('\\', '/', $file), '/Laravel/');

                /** @var list<Global_> $globals */
                $globals = $finder->findInstanceOf($ast, Global_::class);
                foreach ($globals as $globalStmt) {
                    $violations[] = sprintf('[%s] Forbidden `global` declaration in %s:%d.', $package->name, $rel, $globalStmt->getStartLine());
                }

                /** @var list<FuncCall> $funcs */
                $funcs = $finder->findInstanceOf($ast, FuncCall::class);
                foreach ($funcs as $func) {
                    if ($func->name instanceof Name && strtolower($func->name->toString()) === 'env') {
                        $violations[] = sprintf('[%s] Ambient env() call in %s:%d.', $package->name, $rel, $func->getStartLine());
                    }
                }

                /** @var list<ArrayDimFetch> $fetches */
                $fetches = $finder->findInstanceOf($ast, ArrayDimFetch::class);
                /** @var array<int, true> $reportedArgvLines */
                $reportedArgvLines = [];
                foreach ($fetches as $fetch) {
                    if ($isLaravelCliProbe && $fetch->var instanceof Variable && $fetch->var->name === '_SERVER' && $fetch->dim instanceof String_ && $fetch->dim->value === 'argv') {
                        $reportedArgvLines[$fetch->var->getStartLine()] = true;
                    }
                }

                /** @var list<Variable> $variables */
                $variables = $finder->findInstanceOf($ast, Variable::class);
                foreach ($variables as $variable) {
                    if (! is_string($variable->name) || ! in_array($variable->name, self::FORBIDDEN_SUPERGLOBALS, true)) {
                        continue;
                    }

                    if ($variable->name === '_SERVER' && isset($reportedArgvLines[$variable->getStartLine()])) {
                        continue;
                    }

                    $violations[] = sprintf('[%s] Direct superglobal $%s access in %s:%d.', $package->name, $variable->name, $rel, $variable->getStartLine());
                }
            }
        }

        return $violations === []
            ? GateResult::pass($this->id(), $this->category(), ['No unguarded runtime globals detected in package sources.'])
            : GateResult::fail($this->id(), $this->category(), $violations);
    }

    private function isDevHarnessExempt(string $file): bool
    {
        $normalized = str_replace('\\', '/', $file);

        return array_any(self::DEV_HARNESS_EXEMPT, fn (string $segment): bool => str_contains($normalized, $segment));
    }
}
