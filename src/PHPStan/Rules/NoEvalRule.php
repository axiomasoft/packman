<?php

declare(strict_types=1);

namespace Packman\Modules\PHPStan\Rules;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use PhpParser\Node;
use PhpParser\Node\Expr\Eval_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Код модуля должен быть статически проверяем — `eval` выводит произвольный
 * код из-под ревью маркетплейса и статик-анализа.
 *
 * @implements Rule<Eval_>
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class NoEvalRule implements Rule
{
    public function getNodeType(): string
    {
        return Eval_::class;
    }

    /**
     * @param  Eval_  $node
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        return [
            RuleErrorBuilder::message('eval() is forbidden in module code (B-10 §7.3 enforcement list) — it escapes static analysis and marketplace review.')
                ->identifier('modules.eval')
                ->build(),
        ];
    }
}
