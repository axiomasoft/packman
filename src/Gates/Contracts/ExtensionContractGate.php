<?php

declare(strict_types=1);

namespace Packman\DevTools\Gates\Contracts;

use Packman\Attributes\Api;
use Packman\DevTools\Contracts\Gates\QualityGate;
use Packman\DevTools\Data\Gates\GateContext;
use Packman\DevTools\Data\Gates\GateResult;
use Packman\DevTools\Internal\Gates\GateFileWalker;
use PhpParser\Node\Arg;
use PhpParser\Node\ArgPlaceholder;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\VariadicPlaceholder;
use PhpParser\NodeFinder;

/**
 * Verifies module/plugin extension contracts, manifest declarations, and composer extra.packman wiring.
 */
#[Api(since: '1.0')]
final readonly class ExtensionContractGate implements QualityGate
{
    private const string PLUGIN_ID_PATTERN = '/\A[a-z0-9]+(?:-[a-z0-9]+)*(?:\.[a-z0-9]+(?:-[a-z0-9]+)*)+\z/';

    private const string COMPOSER_NAME_PATTERN = '/\A[a-z0-9]([_.-]?[a-z0-9]+)*\/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*\z/';

    private GateFileWalker $walker;

    public function __construct()
    {
        $this->walker = new GateFileWalker;
    }

    public function id(): string
    {
        return 'extension-contract';
    }

    public function category(): string
    {
        return 'contracts';
    }

    public function description(): string
    {
        return 'Validates composer extra.packman metadata and AST plugin/module manifest declarations.';
    }

    public function run(GateContext $context): GateResult
    {
        $violations = [];
        $checkedManifests = 0;
        $finder = new NodeFinder;

        foreach ($context->config->packages as $package) {
            $composer = $context->readComposerJson($package);
            $extra = is_array($composer['extra'] ?? null) ? $composer['extra'] : [];
            $packmanExtra = is_array($extra['packman'] ?? null) ? $extra['packman'] : [];

            $moduleClass = $packmanExtra['module'] ?? null;

            if (is_string($moduleClass) && $moduleClass !== '' && ! class_exists($moduleClass)) {
                $violations[] = "[{$package->name}] composer extra.packman.module class [{$moduleClass}] does not exist.";
            }

            $srcDir = $context->packageDirectory($package).'/src';
            foreach ($this->walker->phpFiles($srcDir) as $file) {
                $ast = $this->walker->parseAst($file);

                if ($ast === null) {
                    continue;
                }

                $rel = $this->walker->relativePath($context->rootPath, $file);

                /** @var list<New_> $news */
                $news = $finder->findInstanceOf($ast, New_::class);
                foreach ($news as $newExpr) {
                    if ($newExpr->class instanceof Name && $newExpr->class->getLast() === 'PluginManifest') {
                        $idArg = $this->resolveArg($newExpr->args, 0, 'id');

                        if ($idArg instanceof String_) {
                            $checkedManifests++;

                            if (preg_match(self::PLUGIN_ID_PATTERN, $idArg->value) !== 1) {
                                $violations[] = "[{$package->name}] Invalid PluginManifest id '{$idArg->value}' in {$rel}:{$newExpr->getStartLine()}.";
                            }
                        }
                    }
                }

                /** @var list<StaticCall> $calls */
                $calls = $finder->findInstanceOf($ast, StaticCall::class);
                foreach ($calls as $call) {
                    if ($call->class instanceof Name && $call->class->getLast() === 'ModuleManifest' && $call->name instanceof Identifier && $call->name->toString() === 'make') {
                        $nameArg = $this->resolveArg($call->args, 0, 'composerName');

                        if ($nameArg instanceof String_) {
                            $checkedManifests++;

                            if (preg_match(self::COMPOSER_NAME_PATTERN, $nameArg->value) !== 1) {
                                $violations[] = "[{$package->name}] Invalid ModuleManifest composerName '{$nameArg->value}' in {$rel}:{$call->getStartLine()}.";
                            }
                        }
                    }
                }
            }
        }

        return $violations === []
            ? GateResult::pass($this->id(), $this->category(), [sprintf('Verified extension contracts and %d inline manifest definitions.', $checkedManifests)])
            : GateResult::fail($this->id(), $this->category(), $violations);
    }

    /** @param array<Arg|ArgPlaceholder|VariadicPlaceholder> $args */
    private function resolveArg(array $args, int $position, string $name): mixed
    {
        foreach ($args as $arg) {
            if ($arg instanceof Arg && $arg->name?->toString() === $name) {
                return $arg->value;
            }
        }

        $positional = $args[$position] ?? null;

        return $positional instanceof Arg ? $positional->value : null;
    }
}
