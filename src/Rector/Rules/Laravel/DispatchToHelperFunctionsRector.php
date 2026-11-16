<?php

declare(strict_types=1);

namespace Packman\DevTools\Rector\Rules\Laravel;

use Illuminate\Foundation\Events\Dispatchable;
use Override;
use Packman\Attributes\Api;
use Packman\DevTools\Internal\Rector\PreservedPaths;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PHPStan\Broker\ClassNotFoundException;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Converts Laravel static ::dispatch()/::dispatchSync()/::broadcast() calls into helper functions outside preserved paths.
 */
#[Api(since: '1.0')]
final class DispatchToHelperFunctionsRector extends AbstractRector
{
    /** @var list<string> */
    private const array PRESERVE_PATH_FRAGMENTS = [
        '/packages/testkit/',
        '/packages/devtools/',
        '/tests/',
    ];

    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
    ) {}

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Use event(), dispatch(), dispatch_sync(), and broadcast() helpers outside preserved harness paths.',
            [
                new CodeSample('ExampleJob::dispatch($payload);', 'dispatch(new ExampleJob($payload));'),
                new CodeSample('ExampleEvent::dispatch($payload);', 'event(new ExampleEvent($payload));'),
            ],
        );
    }

    /** @return list<class-string<Node>> */
    public function getNodeTypes(): array
    {
        return [StaticCall::class];
    }

    #[Override]
    public function refactor(Node $node): ?Node
    {
        if (! $node instanceof StaticCall) {
            return null;
        }

        if (PreservedPaths::matchesAny($this->getFile()->getFilePath(), self::PRESERVE_PATH_FRAGMENTS) || $node->isFirstClassCallable()) {
            return null;
        }

        if (! $this->isNames($node->name, ['dispatch', 'dispatchSync', 'broadcast'])) {
            return null;
        }

        if (! $node->class instanceof Name) {
            return null;
        }

        $reflection = $this->resolveClassReflection($node);

        if (! $reflection instanceof ClassReflection) {
            return null;
        }

        $traits = array_keys($reflection->getTraits(true));

        if ($this->isName($node->name, 'broadcast')) {
            return in_array(Dispatchable::class, $traits, true)
                ? $this->createHelperCall($node, 'broadcast')
                : null;
        }

        if (in_array(\Illuminate\Foundation\Bus\Dispatchable::class, $traits, true)) {
            $helper = $this->isName($node->name, 'dispatchSync') ? 'dispatch_sync' : 'dispatch';

            return $this->createHelperCall($node, $helper);
        }

        if (in_array(Dispatchable::class, $traits, true)) {
            return $this->createHelperCall($node, 'event');
        }

        return null;
    }

    private function resolveClassReflection(StaticCall $call): ?ClassReflection
    {
        $type = $this->getType($call->class);

        if (! $type->isObject()->yes()) {
            return null;
        }

        $names = $type->getObjectClassNames();

        if (count($names) !== 1) {
            return null;
        }

        try {
            return $this->reflectionProvider->getClass($names[0]);
        } catch (ClassNotFoundException) {
            return null;
        }
    }

    private function createHelperCall(StaticCall $call, string $helper): ?FuncCall
    {
        $class = $call->class;

        if (! $class instanceof Name) {
            return null;
        }

        $target = $class->isSpecialClassName()
            ? new Name('static')
            : new FullyQualified($class);

        return $this->nodeFactory->createFuncCall($helper, [new New_($target, $call->args)]);
    }
}
