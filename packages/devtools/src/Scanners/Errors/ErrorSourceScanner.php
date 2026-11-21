<?php

declare(strict_types=1);

namespace Packman\DevTools\Scanners\Errors;

use Packman\Attributes\Api;
use Packman\DevTools\Data\Errors\ErrorCodeEntry;
use Packman\DevTools\Data\Errors\ErrorScanReport;
use Packman\DevTools\Internal\Gates\GateFileWalker;
use Packman\DevTools\Internal\ProjectAutoload;
use Packman\Errors\Contracts\ErrorCode;
use Packman\Errors\Contracts\ProblemDefinitionProvider;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Data\ProblemDefinition;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\EnumCase;
use PhpParser\NodeFinder;
use ReflectionClass;
use Throwable;

/**
 * AST + metadata scanner extracting ErrorCode enums, ErrorDescriptor usages, and ProblemDefinitionProvider entries.
 */
#[Api(since: '1.0')]
final class ErrorSourceScanner
{
    private const string WIRE_CODE_PATTERN = '/\A[a-z0-9]+(?:[-_][a-z0-9]+)*(?:\.[a-z0-9]+(?:[-_][a-z0-9]+)*)+\z/';

    private readonly GateFileWalker $walker;

    public function __construct()
    {
        $this->walker = new GateFileWalker;
    }

    /**
     * @param  list<array{name: string, path: string}>  $packages
     */
    public function scanPackages(string $rootPath, array $packages, string $urnPrefix = 'urn:packman:problem:'): ErrorScanReport
    {
        return ProjectAutoload::run($rootPath, fn (): ErrorScanReport => $this->inspectPackages($rootPath, $packages, $urnPrefix));
    }

    /** @param list<array{name: string, path: string}> $packages */
    private function inspectPackages(string $rootPath, array $packages, string $urnPrefix): ErrorScanReport
    {
        $entries = [];
        $diagnostics = [];
        /** @var array<string, ErrorCodeEntry> $seenCodes */
        $seenCodes = [];

        foreach ($packages as $pkg) {
            $srcDir = rtrim($rootPath, '/').'/'.trim($pkg['path'], '/').'/src';
            $pkgScan = $this->scanDirectory($pkg['name'], $srcDir, $urnPrefix);

            foreach ($pkgScan['diagnostics'] as $diagnostic) {
                $diagnostics[] = $diagnostic;
            }

            foreach ($pkgScan['entries'] as $entry) {
                if (isset($seenCodes[$entry->wireCode])) {
                    $first = $seenCodes[$entry->wireCode];
                    $diagnostics[] = sprintf(
                        '[%s] Duplicate error wire code "%s" in %s::%s (already declared in %s::%s).',
                        $pkg['name'],
                        $entry->wireCode,
                        $entry->enumClass,
                        $entry->caseName,
                        $first->enumClass,
                        $first->caseName,
                    );

                    continue;
                }

                $seenCodes[$entry->wireCode] = $entry;
                $entries[] = $entry;
            }
        }

        usort($entries, static fn (ErrorCodeEntry $a, ErrorCodeEntry $b): int => $a->wireCode <=> $b->wireCode);

        return new ErrorScanReport($entries, $diagnostics);
    }

    /** @return array{entries: list<ErrorCodeEntry>, diagnostics: list<string>} */
    public function scanDirectory(string $packageName, string $srcDir, string $urnPrefix = 'urn:packman:problem:'): array
    {
        if (! is_dir($srcDir)) {
            return ['entries' => [], 'diagnostics' => []];
        }

        $enumCases = [];
        $providers = [];
        $descriptorCodes = [];
        $diagnostics = [];
        $finder = new NodeFinder;

        foreach ($this->walker->phpFiles($srcDir) as $file) {
            $resolved = $this->walker->resolvedAst($file);

            if ($resolved === null) {
                continue;
            }

            /** @var list<Enum_> $enums */
            $enums = $finder->findInstanceOf($resolved, Enum_::class);
            foreach ($enums as $enum) {
                if (! $this->implementsInterface($enum->implements, ErrorCode::class)) {
                    continue;
                }

                $fqcn = $enum->namespacedName?->toString() ?? ($enum->name?->toString() ?? '');
                foreach ($enum->stmts as $stmt) {
                    if (! $stmt instanceof EnumCase) {
                        continue;
                    }

                    $wireCode = $stmt->expr instanceof String_ ? $stmt->expr->value : '';
                    $caseName = $stmt->name->toString();

                    if ($wireCode === '' || preg_match(self::WIRE_CODE_PATTERN, $wireCode) !== 1) {
                        $diagnostics[] = sprintf(
                            '[%s] Invalid error wire code "%s" on %s::%s (%s:%d).',
                            $packageName,
                            $wireCode,
                            $fqcn,
                            $caseName,
                            $file,
                            $stmt->getStartLine(),
                        );
                    }

                    $enumCases[$fqcn.'::'.$caseName] = [
                        'enum' => $fqcn,
                        'case' => $caseName,
                        'wireCode' => $wireCode,
                        'file' => $file,
                        'line' => $stmt->getStartLine(),
                    ];
                }
            }

            /** @var list<Class_> $classes */
            $classes = $finder->findInstanceOf($resolved, Class_::class);
            foreach ($classes as $class) {
                if ($this->implementsInterface($class->implements, ProblemDefinitionProvider::class)) {
                    $fqcn = $class->namespacedName?->toString();

                    if (is_string($fqcn)) {
                        $providers[] = ['class' => $fqcn, 'file' => $file];
                    }
                }
            }

            /** @var list<New_> $news */
            $news = $finder->findInstanceOf($resolved, New_::class);
            foreach ($news as $newExpr) {
                if (! $newExpr->class instanceof Name) {
                    continue;
                }

                if ($newExpr->class->toString() !== ErrorDescriptor::class) {
                    continue;
                }

                $codeArg = $newExpr->args[0] ?? null;
                foreach ($newExpr->args as $arg) {
                    if ($arg instanceof Arg && $arg->name?->toString() === 'code') {
                        $codeArg = $arg;

                        break;
                    }
                }

                if ($codeArg instanceof Arg && $codeArg->value instanceof ClassConstFetch) {
                    $fetch = $codeArg->value;

                    if ($fetch->class instanceof Name && $fetch->name instanceof Identifier) {
                        $descriptorCodes[] = [
                            'target' => $fetch->class->toString().'::'.$fetch->name->toString(),
                            'file' => $file,
                            'line' => $newExpr->getStartLine(),
                        ];
                    }
                }
            }
        }

        /** @var array<string, ProblemDefinition> $definitionsByCode */
        $definitionsByCode = [];
        foreach ($providers as $providerInfo) {
            $className = $providerInfo['class'];

            if (! class_exists($className)) {
                continue;
            }

            try {
                $reflection = new ReflectionClass($className);
                $constructor = $reflection->getConstructor();

                if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
                    continue;
                }

                $instance = $reflection->newInstance();

                if (! $instance instanceof ProblemDefinitionProvider) {
                    continue;
                }

                foreach ($instance->definitions() as $definition) {
                    $wire = $definition->code->value();

                    if ($urnPrefix !== '' && ! str_starts_with($definition->type, $urnPrefix)) {
                        $diagnostics[] = sprintf(
                            '[%s] ProblemDefinition type "%s" for "%s" must start with "%s".',
                            $packageName,
                            $definition->type,
                            $wire,
                            $urnPrefix,
                        );
                    }

                    $definitionsByCode[$wire] = $definition;
                }
            } catch (Throwable $exception) {
                $diagnostics[] = sprintf('[%s] Failed inspecting provider %s: %s', $packageName, $className, $exception->getMessage());
            }
        }

        foreach ($enumCases as $caseInfo) {
            $wire = $caseInfo['wireCode'];

            if ($wire !== '' && ! isset($definitionsByCode[$wire])) {
                $diagnostics[] = sprintf(
                    '[%s] ErrorCode case %s::%s ("%s") is missing a ProblemDefinition in package providers.',
                    $packageName,
                    $caseInfo['enum'],
                    $caseInfo['case'],
                    $wire,
                );
            }
        }

        foreach ($descriptorCodes as $usage) {
            if (isset($enumCases[$usage['target']])) {
                $wire = $enumCases[$usage['target']]['wireCode'];

                if (! isset($definitionsByCode[$wire])) {
                    $diagnostics[] = sprintf(
                        '[%s] ErrorDescriptor references unregistered code "%s" (%s:%d).',
                        $packageName,
                        $wire,
                        $usage['file'],
                        $usage['line'],
                    );
                }
            }
        }

        $entries = [];
        foreach ($enumCases as $caseInfo) {
            $def = $definitionsByCode[$caseInfo['wireCode']] ?? null;
            $entries[] = new ErrorCodeEntry(
                package: $packageName,
                enumClass: $caseInfo['enum'],
                caseName: $caseInfo['case'],
                wireCode: $caseInfo['wireCode'],
                file: $caseInfo['file'],
                line: $caseInfo['line'],
                problemType: $def?->type,
                defaultStatus: $def?->defaultStatus,
                title: $def?->title,
                retryable: $def?->retryable,
                category: $def?->category->value,
            );
        }

        return ['entries' => $entries, 'diagnostics' => $diagnostics];
    }

    /** @param array<Name> $implements */
    private function implementsInterface(array $implements, string $targetInterface): bool
    {
        return array_any($implements, fn (Name $name): bool => $name->toString() === $targetInterface);
    }
}
