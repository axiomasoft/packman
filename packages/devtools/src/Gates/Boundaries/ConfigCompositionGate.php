<?php

declare(strict_types=1);

namespace Packman\DevTools\Gates\Boundaries;

use Packman\Attributes\Api;
use Packman\DevTools\Contracts\Gates\QualityGate;
use Packman\DevTools\Data\Gates\GateContext;
use Packman\DevTools\Data\Gates\GateResult;
use Packman\DevTools\Internal\Gates\GateFileWalker;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\NodeFinder;

/**
 * Prevents domain code outside Config/, Laravel/, Console/, Commands/, Testing/ from reading global config() or env().
 */
#[Api(since: '1.0')]
final readonly class ConfigCompositionGate implements QualityGate
{
    /** @var list<string> */
    private const array EXEMPT_SEGMENTS = ['/Config/', '/Laravel/', '/Console/', '/Commands/', '/Testing/'];

    private GateFileWalker $walker;

    public function __construct()
    {
        $this->walker = new GateFileWalker;
    }

    public function id(): string
    {
        return 'config-composition';
    }

    public function category(): string
    {
        return 'boundaries';
    }

    public function description(): string
    {
        return 'Ensures domain logic receives compiled config objects rather than reading ambient config(), env(), or Config facade.';
    }

    public function run(GateContext $context): GateResult
    {
        $violations = [];
        $finder = new NodeFinder;

        foreach ($context->config->packages as $package) {
            if ($package->devOnly) {
                continue;
            }

            $srcDir = $context->packageDirectory($package).'/src';
            foreach ($this->walker->phpFiles($srcDir) as $file) {
                if ($this->isExempt($file)) {
                    continue;
                }

                $ast = $this->walker->parseAst($file);

                if ($ast === null) {
                    continue;
                }

                $rel = $this->walker->relativePath($context->rootPath, $file);

                /** @var list<FuncCall> $funcCalls */
                $funcCalls = $finder->findInstanceOf($ast, FuncCall::class);
                foreach ($funcCalls as $call) {
                    if ($call->name instanceof Name && in_array(strtolower($call->name->toString()), ['config', 'env'], true)) {
                        $violations[] = sprintf('[%s] Ambient %s() call in %s:%d violates config composition.', $package->name, $call->name->toString(), $rel, $call->getStartLine());
                    }
                }

                /** @var list<StaticCall> $staticCalls */
                $staticCalls = $finder->findInstanceOf($ast, StaticCall::class);
                foreach ($staticCalls as $call) {
                    if ($call->class instanceof Name && $call->name instanceof Identifier) {
                        $short = $call->class->getLast();

                        if ($short === 'Config' && in_array(strtolower($call->name->toString()), ['get', 'has', 'set', 'all', 'string', 'integer', 'boolean', 'array'], true)) {
                            $violations[] = sprintf('[%s] Static Config::%s() call in %s:%d violates config composition.', $package->name, $call->name->toString(), $rel, $call->getStartLine());
                        }
                    }
                }
            }
        }

        return $violations === []
            ? GateResult::pass($this->id(), $this->category(), ['Domain packages rely on compiled options/config objects only.'])
            : GateResult::fail($this->id(), $this->category(), $violations);
    }

    private function isExempt(string $file): bool
    {
        if (str_ends_with($file, 'ServiceProvider.php')) {
            return true;
        }

        $normalized = str_replace('\\', '/', $file);

        return array_any(self::EXEMPT_SEGMENTS, fn (string $segment): bool => str_contains($normalized, $segment));
    }
}
