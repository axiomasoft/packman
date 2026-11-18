<?php

declare(strict_types=1);

namespace Packman\DevTools\Internal\Rector;

use Packman\Attributes\Internal;
use PhpParser\Node;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Isset_;
use PhpParser\Node\Expr\List_;
use PhpParser\Node\Expr\PostDec;
use PhpParser\Node\Expr\PostInc;
use PhpParser\Node\Expr\PreDec;
use PhpParser\Node\Expr\PreInc;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\InterpolatedString;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Unset_;
use PhpParser\NodeVisitorAbstract;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Reflection\ReflectionProvider;
use Rector\Contract\PhpParser\DecoratingNodeVisitorInterface;
use Rector\NodeNameResolver\NodeNameResolver;
use Rector\PhpDocParser\NodeTraverser\SimpleCallableNodeTraverser;

#[Internal]
final class ArrayDimFetchContextNodeVisitor extends NodeVisitorAbstract implements DecoratingNodeVisitorInterface
{
    public const string IS_IN_SUPERGLOBAL_ASSIGN = 'packman_is_in_superglobal_assign';

    public const string IS_IN_WRITE_CONTEXT = 'packman_is_in_write_context';

    public const string IS_IN_INTERPOLATED_STRING = 'packman_is_in_interpolated_string';

    /** @var list<string> */
    private const array SUPERGLOBAL_NAMES = ['_SERVER', '_GET', '_POST', '_REQUEST', '_ENV'];

    public function __construct(
        private readonly NodeNameResolver $nodeNameResolver,
        private readonly ReflectionProvider $reflectionProvider,
    ) {}

    public function enterNode(Node $node): ?Node
    {
        $this->markWriteContext($node);

        if (! $node instanceof ArrayDimFetch
            && in_array($node::class, [Assign::class, Isset_::class, Unset_::class, InterpolatedString::class], true)
            && (! $node instanceof Assign || $this->isSuperglobalAssign($node))) {
            SimpleCallableNodeTraverser::traverseNodesWithCallable($node, static function (Node $subNode): null {
                if ($subNode instanceof ArrayDimFetch || $subNode instanceof Variable) {
                    $subNode->setAttribute(self::IS_IN_SUPERGLOBAL_ASSIGN, true);
                }

                return null;
            });
        }

        return null;
    }

    private function markWriteContext(Node $node): void
    {
        if ($node instanceof InterpolatedString) {
            SimpleCallableNodeTraverser::traverseNodesWithCallable($node, static function (Node $subNode): null {
                if ($subNode instanceof ArrayDimFetch || $subNode instanceof Variable) {
                    $subNode->setAttribute(self::IS_IN_INTERPOLATED_STRING, true);
                }

                return null;
            });

            return;
        }

        if ($node instanceof Assign || $node instanceof AssignOp) {
            $this->markWriteTarget($node->var);

            return;
        }

        if ($node instanceof AssignRef) {
            $this->markWriteTarget($node->var);
            $this->markWriteTarget($node->expr);

            return;
        }

        if ($node instanceof PreInc || $node instanceof PostInc || $node instanceof PreDec || $node instanceof PostDec) {
            $this->markWriteTarget($node->var);

            return;
        }

        if ($node instanceof Foreach_) {
            if ($node->keyVar instanceof Expr) {
                $this->markWriteTarget($node->keyVar);
            }

            $this->markWriteTarget($node->valueVar);

            return;
        }

        if ($node instanceof Isset_ || $node instanceof Unset_) {
            foreach ($node->vars as $var) {
                $this->markWriteTarget($var);
            }

            return;
        }

        if ($node instanceof FuncCall) {
            $this->markByRefArgs($node);
        }
    }

    private function markWriteTarget(Expr $expr): void
    {
        if ($expr instanceof List_ || $expr instanceof Array_) {
            foreach ($expr->items as $item) {
                if ($item instanceof ArrayItem) {
                    $this->markWriteTarget($item->value);
                }
            }

            return;
        }

        while ($expr instanceof ArrayDimFetch) {
            $expr->setAttribute(self::IS_IN_WRITE_CONTEXT, true);
            $expr = $expr->var;
        }

        if ($expr instanceof Variable) {
            $expr->setAttribute(self::IS_IN_WRITE_CONTEXT, true);
        }
    }

    private function markByRefArgs(FuncCall $funcCall): void
    {
        if ($funcCall->isFirstClassCallable() || ! $funcCall->name instanceof Name || ! $this->reflectionProvider->hasFunction($funcCall->name, null)) {
            return;
        }

        $parameters = ParametersAcceptorSelector::combineAcceptors(
            $this->reflectionProvider->getFunction($funcCall->name, null)->getVariants(),
        )->getParameters();

        $lastParameter = $parameters === [] ? null : $parameters[count($parameters) - 1];

        foreach ($funcCall->getArgs() as $position => $arg) {
            $parameter = $arg->name instanceof Identifier
                ? $this->findParameterByName($parameters, $arg->name->toString())
                : ($parameters[$position] ?? ($lastParameter?->isVariadic() === true ? $lastParameter : null));

            if ($parameter !== null && $parameter->passedByReference()->yes()) {
                $this->markWriteTarget($arg->value);
            }
        }
    }

    /** @param array<int, ParameterReflection> $parameters */
    private function findParameterByName(array $parameters, string $name): ?ParameterReflection
    {
        foreach ($parameters as $parameter) {
            if ($parameter->getName() === $name) {
                return $parameter;
            }
        }

        return null;
    }

    private function isSuperglobalAssign(Assign $assign): bool
    {
        if ($assign->var instanceof ArrayDimFetch && $assign->var->var instanceof Variable) {
            return $this->nodeNameResolver->isNames($assign->var->var, self::SUPERGLOBAL_NAMES);
        }

        if ($assign->var instanceof Variable) {
            return $this->nodeNameResolver->isNames($assign->var, self::SUPERGLOBAL_NAMES);
        }

        return false;
    }
}
