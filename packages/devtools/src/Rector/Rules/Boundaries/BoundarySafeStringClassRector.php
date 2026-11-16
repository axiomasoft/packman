<?php

declare(strict_types=1);

namespace Packman\DevTools\Rector\Rules\Boundaries;

use Override;
use Packman\Attributes\Api;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\String_;
use Rector\Contract\Rector\ConfigurableRectorInterface;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\ConfiguredCodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Rewrites resolved FQCN string literals to ::class constants while preserving optional boundary probes.
 */
#[Api(since: '1.0')]
final class BoundarySafeStringClassRector extends AbstractRector implements ConfigurableRectorInterface
{
    public const string PRESERVED_PREFIXES_KEY = 'preserved_prefixes';

    public const string PRESERVED_CLASSES_KEY = 'preserved_classes';

    private const string PRESERVE_ATTR = 'packman_preserve_class_string';

    /** @var list<string> */
    private const array PROBING_FUNCTIONS = [
        'class_exists',
        'interface_exists',
        'trait_exists',
        'enum_exists',
        'is_a',
        'is_subclass_of',
    ];

    /** @var list<string> */
    private array $preservedPrefixes = [
        'Packman\\Modules\\',
        'Packman\\Plugins\\',
        'Packman\\Panels\\',
        'Packman\\Filament\\',
        'Illuminate\\',
    ];

    /** @var list<string> */
    private array $preservedClasses = [];

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Converts resolvable FQCN strings into ::class constants while preserving boundary probes and configured prefixes.',
            [
                new ConfiguredCodeSample(
                    '$class = "Packman\\Attributes\\Api";',
                    '$class = \Packman\Attributes\Api::class;',
                    [self::PRESERVED_PREFIXES_KEY => ['Packman\\Modules\\']],
                ),
            ],
        );
    }

    /** @param mixed[] $configuration */
    public function configure(array $configuration): void
    {
        if (is_array($configuration[self::PRESERVED_PREFIXES_KEY] ?? null)) {
            $this->preservedPrefixes = array_values(array_filter(
                $configuration[self::PRESERVED_PREFIXES_KEY],
                static fn (mixed $v): bool => is_string($v) && $v !== '',
            ));
        }

        if (is_array($configuration[self::PRESERVED_CLASSES_KEY] ?? null)) {
            $this->preservedClasses = array_values(array_filter(
                $configuration[self::PRESERVED_CLASSES_KEY],
                static fn (mixed $v): bool => is_string($v) && $v !== '',
            ));
        }
    }

    /** @return list<class-string<Node>> */
    public function getNodeTypes(): array
    {
        return [FuncCall::class, String_::class];
    }

    #[Override]
    public function refactor(Node $node): ?Node
    {
        if ($node instanceof FuncCall) {
            if ($node->name instanceof Name && in_array(strtolower($node->name->toString()), self::PROBING_FUNCTIONS, true)) {
                foreach ($node->args as $arg) {
                    if ($arg instanceof Arg && $arg->value instanceof String_) {
                        $arg->value->setAttribute(self::PRESERVE_ATTR, true);
                    }
                }
            }

            return null;
        }

        if (! $node instanceof String_ || $node->getAttribute(self::PRESERVE_ATTR) === true) {
            return null;
        }

        $value = ltrim($node->value, '\\');

        if (! str_contains($value, '\\')) {
            return null;
        }

        if (in_array($value, $this->preservedClasses, true)) {
            return null;
        }

        foreach ($this->preservedPrefixes as $prefix) {
            if (str_starts_with($value, ltrim($prefix, '\\'))) {
                return null;
            }
        }

        if (! class_exists($value) && ! interface_exists($value) && ! enum_exists($value)) {
            return null;
        }

        return new ClassConstFetch(new FullyQualified($value), 'class');
    }
}
