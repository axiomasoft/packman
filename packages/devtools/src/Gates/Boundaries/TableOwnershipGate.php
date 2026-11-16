<?php

declare(strict_types=1);

namespace Packman\DevTools\Gates\Boundaries;

use Packman\Attributes\Api;
use Packman\DevTools\Contracts\Gates\QualityGate;
use Packman\DevTools\Data\Gates\GateContext;
use Packman\DevTools\Data\Gates\GateResult;
use Packman\DevTools\Internal\Gates\GateFileWalker;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;

/**
 * Verifies Schema::create() migration table names have unique owning packages and valid prefixes.
 */
#[Api(since: '1.0')]
final readonly class TableOwnershipGate implements QualityGate
{
    private const string TABLE_NAME_PATTERN = '/\A[a-z][a-z0-9_]*\z/';

    private GateFileWalker $walker;

    public function __construct()
    {
        $this->walker = new GateFileWalker;
    }

    public function id(): string
    {
        return 'table-ownership';
    }

    public function category(): string
    {
        return 'boundaries';
    }

    public function description(): string
    {
        return 'Detects conflicting Schema::create() table ownership and invalid SQL table identifiers across migrations.';
    }

    public function run(GateContext $context): GateResult
    {
        $violations = [];
        /** @var array<string, string> $owners */
        $owners = [];
        $finder = new NodeFinder;

        foreach ($context->config->packages as $package) {
            $migrationsDir = $context->packageDirectory($package).'/database/migrations';
            foreach ($this->walker->phpFiles($migrationsDir) as $file) {
                $ast = $this->walker->parseAst($file);

                if ($ast === null) {
                    continue;
                }

                $rel = $this->walker->relativePath($context->rootPath, $file);
                /** @var list<StaticCall> $calls */
                $calls = $finder->findInstanceOf($ast, StaticCall::class);

                foreach ($calls as $call) {
                    if (! $call->class instanceof Name || ! $call->name instanceof Identifier) {
                        continue;
                    }

                    if ($call->class->getLast() !== 'Schema' || strtolower($call->name->toString()) !== 'create') {
                        continue;
                    }

                    $firstArg = $call->args[0] ?? null;

                    if (! $firstArg instanceof Arg || ! $firstArg->value instanceof String_) {
                        continue;
                    }

                    $table = $firstArg->value->value;

                    if (preg_match(self::TABLE_NAME_PATTERN, $table) !== 1) {
                        $violations[] = "[{$package->name}] Invalid table identifier '{$table}' in {$rel}.";

                        continue;
                    }

                    if (isset($owners[$table]) && $owners[$table] !== $package->name) {
                        $violations[] = "[{$package->name}] Table '{$table}' in {$rel} collides with owner [{$owners[$table]}].";

                        continue;
                    }

                    $owners[$table] = $package->name;
                }
            }
        }

        return $violations === []
            ? GateResult::pass($this->id(), $this->category(), [sprintf('Verified unique ownership across %d migration tables.', count($owners))])
            : GateResult::fail($this->id(), $this->category(), $violations);
    }
}
