<?php

declare(strict_types=1);

namespace Packman\DevTools\Rector\Rules\Laravel;

use Illuminate\Support\Facades\Request;
use Override;
use Packman\Attributes\Api;
use Packman\DevTools\Internal\Rector\PreservedPaths;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Scalar\String_;
use Rector\Rector\AbstractRector;
use RectorLaravel\NodeVisitor\ArrayDimFetchContextNodeVisitor;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Rewrites $_SERVER['KEY'] reads to Request::server('KEY') outside allowlisted CLI/harness/testing paths.
 */
#[Api(since: '1.0')]
final class ServerVariableToRequestFacadeRector extends AbstractRector
{
    /** @var list<string> */
    private const array PRESERVE_PATH_FRAGMENTS = [
        '/packages/testkit/',
        '/packages/devtools/',
        '/src/Laravel/',
        '/src/Console/',
        '/src/Commands/',
        '/Testing/',
        '/tests/',
    ];

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Replace domain $_SERVER reads with Request::server() while preserving CLI/harness/testing paths.',
            [
                new CodeSample(
                    '\$host = \$_SERVER[\'HTTP_HOST\'];',
                    '\$host = \Illuminate\Support\Facades\Request::server(\'HTTP_HOST\');',
                ),
            ],
        );
    }

    /** @return list<class-string<Node>> */
    public function getNodeTypes(): array
    {
        return [ArrayDimFetch::class];
    }

    #[Override]
    public function refactor(Node $node): ?Node
    {
        if (! $node instanceof ArrayDimFetch) {
            return null;
        }

        if (PreservedPaths::matchesAny($this->getFile()->getFilePath(), self::PRESERVE_PATH_FRAGMENTS)) {
            return null;
        }

        if (! $this->isName($node->var, '_SERVER') || ! $node->dim instanceof Expr) {
            return null;
        }

        if ($node->dim instanceof String_ && $node->dim->value === 'argv') {
            return null;
        }

        if ($node->getAttribute(ArrayDimFetchContextNodeVisitor::IS_IN_SUPERGLOBAL_ASSIGN) === true) {
            return null;
        }

        if ($node->getAttribute(ArrayDimFetchContextNodeVisitor::IS_IN_WRITE_CONTEXT) === true) {
            return null;
        }

        return $this->nodeFactory->createStaticCall(Request::class, 'server', [
            new Arg($node->dim),
        ]);
    }
}
