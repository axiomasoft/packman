<?php

declare(strict_types=1);

namespace Packman\DevTools\Gates\Quality;

use Packman\Attributes\Api;
use Packman\DevTools\Contracts\Gates\QualityGate;
use Packman\DevTools\Data\Gates\GateContext;
use Packman\DevTools\Data\Gates\GateResult;
use Packman\DevTools\Internal\Gates\GateFileWalker;
use PhpParser\Node\Expr\Eval_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\NodeFinder;

/**
 * Enforces structural quality ceilings: zero eval(), zero debug dumps, zero extract(), zero inline phpstan-ignore suppressions in runtime src/.
 */
#[Api(since: '1.0')]
final readonly class QualityCeilingGuard implements QualityGate
{
    /** @var list<string> */
    private const array FORBIDDEN_FUNCTIONS = ['dd', 'dump', 'var_dump', 'extract', 'compact'];

    private GateFileWalker $walker;

    public function __construct(
        private int $maxFileLines = 1500,
    ) {
        $this->walker = new GateFileWalker;
    }

    public function id(): string
    {
        return 'quality-ceiling';
    }

    public function category(): string
    {
        return 'quality';
    }

    public function description(): string
    {
        return 'Enforces file size ceiling and blocks eval(), debug dumps, extract(), and inline @phpstan-ignore suppressions.';
    }

    public function run(GateContext $context): GateResult
    {
        $violations = [];
        $finder = new NodeFinder;
        $checked = 0;

        foreach ($context->config->packages as $package) {
            $srcDir = $context->packageDirectory($package).'/src';
            foreach ($this->walker->phpFiles($srcDir) as $file) {
                $checked++;
                $rel = $this->walker->relativePath($context->rootPath, $file);
                $lines = file($file);
                $lineCount = is_array($lines) ? count($lines) : 0;

                if ($lineCount > $this->maxFileLines) {
                    $violations[] = "[{$package->name}] {$rel} has {$lineCount} lines, exceeding ceiling {$this->maxFileLines}.";
                }

                $raw = file_get_contents($file);

                if (! $package->devOnly && is_string($raw) && str_contains($raw, '@phpstan-ignore')) {
                    $violations[] = "[{$package->name}] Inline @phpstan-ignore suppression forbidden in {$rel}.";
                }

                $ast = $this->walker->parseAst($file);

                if ($ast === null) {
                    continue;
                }

                /** @var list<Eval_> $evals */
                $evals = $finder->findInstanceOf($ast, Eval_::class);
                foreach ($evals as $eval) {
                    $violations[] = "[{$package->name}] Forbidden eval() in {$rel}:{$eval->getStartLine()}.";
                }

                /** @var list<FuncCall> $calls */
                $calls = $finder->findInstanceOf($ast, FuncCall::class);
                foreach ($calls as $call) {
                    if ($call->name instanceof Name && in_array(strtolower($call->name->toString()), self::FORBIDDEN_FUNCTIONS, true)) {
                        $violations[] = "[{$package->name}] Forbidden {$call->name->toString()}() call in {$rel}:{$call->getStartLine()}.";
                    }
                }
            }
        }

        return $violations === []
            ? GateResult::pass($this->id(), $this->category(), [sprintf('Verified quality ceilings across %d source files.', $checked)])
            : GateResult::fail($this->id(), $this->category(), $violations);
    }
}
