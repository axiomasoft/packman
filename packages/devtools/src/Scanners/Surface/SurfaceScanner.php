<?php

declare(strict_types=1);

namespace Packman\DevTools\Scanners\Surface;

use Packman\Attributes\Api;
use Packman\Attributes\Internal;
use Packman\Attributes\Spi;
use Packman\DevTools\Data\Surface\SourceType;
use Packman\DevTools\Data\Surface\SurfaceDiagnostic;
use Packman\DevTools\Data\Surface\SurfaceReport;
use Packman\DevTools\Internal\Gates\GateFileWalker;
use PhpParser\Node;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\ComplexType;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\EnumCase;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\UnionType;
use PhpParser\PrettyPrinter\Standard;

/**
 * AST scanner extracting #[Api], #[Spi], and #[Internal] surfaces into deterministic php-api/1 manifests.
 */
#[Api(since: '1.0')]
final class SurfaceScanner
{
    private readonly Standard $printer;

    private readonly GateFileWalker $walker;

    public function __construct()
    {
        $this->printer = new Standard;
        $this->walker = new GateFileWalker;
    }

    public function scanPackage(string $packageName, string $srcDirectory): SurfaceReport
    {
        $types = $this->collectTypes($srcDirectory);

        /** @var array<string, bool> $strictInternalClasses */
        $strictInternalClasses = [];
        foreach ($types as $type) {
            if ($this->hasAttribute($type, Internal::class)) {
                $strictInternalClasses[$type->name] = true;
            }
        }

        $symbols = [];
        $diagnostics = [];

        foreach ($types as $type) {
            $hasApi = $this->findAttribute($type, Api::class);
            $hasSpi = $this->findAttribute($type, Spi::class);
            $hasInternal = $this->hasAttribute($type, Internal::class);

            if ($hasInternal && ($hasApi !== null || $hasSpi !== null)) {
                $diagnostics[] = new SurfaceDiagnostic(
                    code: 'surface.internal_conflict',
                    symbol: $type->name,
                    file: $type->file,
                    line: $type->line,
                    message: 'Internal type cannot be marked #[Api] or #[Spi].',
                );

                continue;
            }

            if ($hasInternal) {
                continue;
            }

            if ($hasApi === null && $hasSpi === null) {
                $diagnostics[] = new SurfaceDiagnostic(
                    code: 'surface.missing_attribute',
                    symbol: $type->name,
                    file: $type->file,
                    line: $type->line,
                    message: 'Every type must carry #[Api], #[Spi], or #[Internal], including types in an Internal namespace.',
                );

                continue;
            }

            if ($hasApi !== null && $hasSpi !== null) {
                $diagnostics[] = new SurfaceDiagnostic(
                    code: 'surface.ambiguous_layer',
                    symbol: $type->name,
                    file: $type->file,
                    line: $type->line,
                    message: 'Type cannot carry both #[Api] and #[Spi].',
                );

                continue;
            }

            if (! $this->isWiringOrTestClass($type->name)) {
                foreach ($type->referencedTypes as $referenced) {
                    if (isset($strictInternalClasses[$referenced]) || $this->isCrossPackageInternalReference($type->name, $referenced)) {
                        $diagnostics[] = new SurfaceDiagnostic(
                            code: 'surface.internal_leak',
                            symbol: $type->name,
                            file: $type->file,
                            line: $type->line,
                            message: "Public/SPI signature references internal type [{$referenced}].",
                        );
                    }
                }
            }

            $layer = $hasApi !== null ? 'api' : 'spi';
            $attrArgs = $hasApi !== null ? $hasApi['args'] : $hasSpi['args'];
            $since = $this->resolveSince($attrArgs);
            $spiKind = $hasSpi !== null ? $this->resolveSpiKind($attrArgs) : null;

            $entry = [
                'kind' => $type->kind,
                'layer' => $layer,
                'since' => $since,
                'final' => $type->final,
                'readonly' => $type->readonly,
                'abstract' => $type->abstract,
                'extends' => $type->extends,
                'implements' => $type->implements,
                'uses' => $type->uses,
                'doc' => $type->doc,
                'methods' => $type->methods === [] ? (object) [] : $type->methods,
                'properties' => $type->properties === [] ? (object) [] : $type->properties,
                'constants' => $type->constants,
                'enum_backing' => $type->enumBacking,
                'enum_cases' => $type->enumCases,
            ];

            if ($spiKind !== null) {
                $entry['spi_kind'] = $spiKind;
            }

            $symbols[$type->name] = $entry;
        }

        ksort($symbols, SORT_STRING);

        return new SurfaceReport($packageName, $symbols, $diagnostics);
    }

    /** @return list<SourceType> */
    private function collectTypes(string $srcDirectory): array
    {
        if (! is_dir($srcDirectory)) {
            return [];
        }

        $types = [];
        foreach ($this->walker->phpFiles($srcDirectory) as $path) {
            $resolved = $this->walker->resolvedAst($path);

            if ($resolved === null) {
                continue;
            }

            foreach ($this->extractFromStatements($resolved, $path) as $type) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * @param  array<Stmt>  $statements
     * @return list<SourceType>
     */
    private function extractFromStatements(array $statements, string $file): array
    {
        $found = [];

        foreach ($statements as $stmt) {
            if ($stmt instanceof Namespace_) {
                foreach ($this->extractFromStatements($stmt->stmts, $file) as $nested) {
                    $found[] = $nested;
                }

                continue;
            }

            if ($stmt instanceof Class_ || $stmt instanceof Interface_ || $stmt instanceof Trait_ || $stmt instanceof Enum_) {
                $fqcn = $stmt->namespacedName?->toString() ?? $stmt->name?->toString();

                if ($fqcn === null) {
                    continue;
                }

                $found[] = $this->buildSourceType($stmt, $fqcn, $file);
            }
        }

        return $found;
    }

    private function buildSourceType(Class_|Interface_|Trait_|Enum_ $stmt, string $fqcn, string $file): SourceType
    {
        $kind = match (true) {
            $stmt instanceof Interface_ => 'interface',
            $stmt instanceof Trait_ => 'trait',
            $stmt instanceof Enum_ => 'enum',
            default => 'class',
        };

        $extends = [];
        $implements = [];
        $uses = [];
        $methods = [];
        $properties = [];
        $constants = [];
        $enumCases = [];
        $referencedTypes = [];

        if ($stmt instanceof Class_ && $stmt->extends !== null) {
            $name = $stmt->extends->toString();
            $extends[] = $name;
            $referencedTypes[] = $name;
        }

        if ($stmt instanceof Interface_) {
            foreach ($stmt->extends as $parent) {
                $name = $parent->toString();
                $extends[] = $name;
                $referencedTypes[] = $name;
            }
        }

        if ($stmt instanceof Class_ || $stmt instanceof Enum_) {
            foreach ($stmt->implements as $iface) {
                $name = $iface->toString();
                $implements[] = $name;
                $referencedTypes[] = $name;
            }
        }

        foreach ($stmt->stmts as $member) {
            if ($member instanceof TraitUse) {
                foreach ($member->traits as $trait) {
                    $uses[] = $trait->toString();
                }

                continue;
            }

            if ($member instanceof ClassMethod || $member instanceof Property || $member instanceof ClassConst) {
                $internal = $this->isInternalMember($member->attrGroups);

                if ($member instanceof ClassMethod && strtolower($member->name->toString()) === '__construct' && ($member->isPrivate() || $internal)) {
                    foreach ($member->params as $param) {
                        if (! $param->isPromoted() || $param->isPrivate() || $this->isInternalMember($param->attrGroups)) {
                            continue;
                        }
                        $properties[$this->paramName($param)] = [
                            'flags' => $param->flags,
                            'type' => $this->typeToString($param->type, $referencedTypes),
                            'readonly' => $param->isReadonly() || ($stmt instanceof Class_ && $stmt->isReadonly()),
                            'visibility' => $param->isProtected() ? 'protected' : 'public',
                            'static' => false,
                            'default' => null,
                            'doc' => [],
                            'hooks' => [],
                        ];
                    }
                }

                if ($internal) {
                    continue;
                }
            }

            if ($member instanceof ClassMethod && ! $member->isPrivate()) {
                $isConstructor = strtolower($member->name->toString()) === '__construct';
                $isDocInternal = $this->hasInternalDocTag($member);
                $methodRefs = [];
                $params = [];
                foreach ($member->params as $param) {
                    $typeString = $this->typeToString($param->type, $methodRefs);
                    $params[] = [
                        'name' => $this->paramName($param),
                        'type' => $typeString,
                        'by_reference' => $param->byRef,
                        'variadic' => $param->variadic,
                        'optional' => $param->default !== null,
                        'default' => $param->default !== null ? $this->printer->prettyPrintExpr($param->default) : null,
                        'flags' => $param->flags,
                    ];
                }

                $returnString = $this->typeToString($member->returnType, $methodRefs);

                if (! $isConstructor && ! $isDocInternal) {
                    foreach ($methodRefs as $ref) {
                        $referencedTypes[] = $ref;
                    }
                }

                $methods[$member->name->toString()] = [
                    'visibility' => $member->isProtected() ? 'protected' : 'public',
                    'static' => $member->isStatic(),
                    'final' => $member->isFinal(),
                    'abstract' => $member->isAbstract(),
                    'by_reference' => $member->returnsByRef(),
                    'parameters' => $params,
                    'return' => $returnString,
                    'doc' => [],
                ];

                continue;
            }

            if ($member instanceof Property && ! $member->isPrivate()) {
                $typeString = $this->typeToString($member->type, $referencedTypes);
                foreach ($member->props as $prop) {
                    $properties[$prop->name->toString()] = [
                        'flags' => $member->flags,
                        'type' => $typeString,
                        'readonly' => $member->isReadonly() || ($stmt instanceof Class_ && $stmt->isReadonly()),
                        'visibility' => $member->isProtected() ? 'protected' : 'public',
                        'static' => $member->isStatic(),
                        'default' => $prop->default !== null ? $this->printer->prettyPrintExpr($prop->default) : null,
                        'doc' => [],
                        'hooks' => [],
                    ];
                }

                continue;
            }

            if ($member instanceof ClassConst && ! $member->isPrivate()) {
                foreach ($member->consts as $const) {
                    $constants[] = [
                        'name' => $const->name->toString(),
                        'type' => $this->typeToString($member->type, $referencedTypes),
                        'visibility' => $member->isProtected() ? 'protected' : 'public',
                        'final' => $member->isFinal(),
                    ];
                }

                continue;
            }

            if ($member instanceof EnumCase) {
                $enumCases[] = [
                    'name' => $member->name->toString(),
                    'value' => $member->expr !== null ? $this->printer->prettyPrintExpr($member->expr) : null,
                ];
            }
        }

        ksort($methods, SORT_STRING);
        ksort($properties, SORT_STRING);

        return new SourceType(
            name: $fqcn,
            kind: $kind,
            file: $file,
            line: $stmt->getStartLine(),
            final: ($stmt instanceof Class_ && $stmt->isFinal()) || $stmt instanceof Enum_,
            readonly: $stmt instanceof Class_ && $stmt->isReadonly(),
            abstract: $stmt instanceof Class_ && $stmt->isAbstract(),
            extends: $extends,
            implements: $implements,
            uses: $uses,
            doc: [],
            methods: $methods,
            properties: $properties,
            constants: $constants,
            enumBacking: $stmt instanceof Enum_ && $stmt->scalarType !== null ? $stmt->scalarType->toString() : null,
            enumCases: $enumCases,
            attributes: $this->extractAttributes($stmt->attrGroups),
            referencedTypes: array_values(array_unique($referencedTypes)),
            docInternal: $this->hasInternalDocTag($stmt),
        );
    }

    /**
     * @param  array<AttributeGroup>  $groups
     * @return list<array{name: string, args: array<int|string, mixed>}>
     */
    private function extractAttributes(array $groups): array
    {
        $parsed = [];
        foreach ($groups as $group) {
            foreach ($group->attrs as $attr) {
                $args = [];
                foreach ($attr->args as $index => $arg) {
                    $key = $arg->name?->toString() ?? $index;
                    $args[$key] = $this->printer->prettyPrintExpr($arg->value);
                }

                $parsed[] = [
                    'name' => $attr->name->toString(),
                    'args' => $args,
                ];
            }
        }

        return $parsed;
    }

    /** @param array<AttributeGroup> $groups */
    private function isInternalMember(array $groups): bool
    {
        foreach ($groups as $group) {
            foreach ($group->attrs as $attribute) {
                if ($attribute->name->toString() === Internal::class) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return ?array{name: string, args: array<int|string, mixed>} */
    private function findAttribute(SourceType $type, string $attributeClass): ?array
    {
        foreach ($type->attributes as $attr) {
            if ($attr['name'] === $attributeClass) {
                return $attr;
            }
        }

        return null;
    }

    private function hasAttribute(SourceType $type, string $attributeClass): bool
    {
        return $this->findAttribute($type, $attributeClass) !== null;
    }

    private function isInternalNamespace(string $fqcn): bool
    {
        return str_contains('\\'.$fqcn, '\\Internal\\');
    }

    private function isWiringOrTestClass(string $fqcn): bool
    {
        return str_contains('\\'.$fqcn, '\\Laravel\\') || str_contains('\\'.$fqcn, '\\Testing\\');
    }

    private function isCrossPackageInternalReference(string $ownerFqcn, string $referencedFqcn): bool
    {
        if (! $this->isInternalNamespace($referencedFqcn)) {
            return false;
        }

        $ownerParts = explode('\\', $ownerFqcn);
        $refParts = explode('\\', $referencedFqcn);

        return $ownerParts[0] !== $refParts[0] || ($ownerParts[1] ?? '') !== ($refParts[1] ?? '');
    }

    private function hasInternalDocTag(Node $node): bool
    {
        $doc = $node->getDocComment();

        if ($doc === null) {
            return false;
        }

        return preg_match('/@internal\b/', $doc->getText()) === 1;
    }

    /** @param array<int|string, mixed> $args */
    private function resolveSince(array $args): string
    {
        $raw = $args['since'] ?? $args[1] ?? $args[0] ?? "'1.0'";

        if (is_string($raw) && (str_contains($raw, 'SpiKind') || str_contains($raw, '::'))) {
            $raw = $args['since'] ?? $args[1] ?? "'1.0'";
        }

        if (! is_string($raw)) {
            return '1.0';
        }

        $trimmed = trim($raw, " \t\n\r\0\x0B'\"");

        return $trimmed !== '' ? $trimmed : '1.0';
    }

    /** @param array<int|string, mixed> $args */
    private function resolveSpiKind(array $args): ?string
    {
        $raw = $args['kind'] ?? $args[0] ?? null;

        if (! is_string($raw)) {
            return null;
        }

        if (str_contains($raw, '::Implement')) {
            return 'implement';
        }

        if (str_contains($raw, '::Call')) {
            return 'call';
        }

        return null;
    }

    /** @param list<string> $referenced */
    private function typeToString(Node|ComplexType|null $type, array &$referenced): ?string
    {
        if ($type === null) {
            return null;
        }

        if ($type instanceof Identifier) {
            return $type->toString();
        }

        if ($type instanceof Name) {
            $fqcn = $type->toString();
            $referenced[] = $fqcn;

            return $fqcn;
        }

        if ($type instanceof NullableType) {
            $inner = $this->typeToString($type->type, $referenced);

            return $inner !== null ? '?'.$inner : null;
        }

        if ($type instanceof UnionType) {
            $parts = [];
            foreach ($type->types as $member) {
                $rendered = $this->typeToString($member, $referenced);

                if ($rendered !== null) {
                    $parts[] = $rendered;
                }
            }

            return implode('|', $parts);
        }

        if ($type instanceof IntersectionType) {
            $parts = [];
            foreach ($type->types as $member) {
                $rendered = $this->typeToString($member, $referenced);

                if ($rendered !== null) {
                    $parts[] = $rendered;
                }
            }

            return implode('&', $parts);
        }

        return $this->printer->prettyPrint([$type]);
    }

    private function paramName(Param $param): string
    {
        if ($param->var instanceof Variable && is_string($param->var->name)) {
            return $param->var->name;
        }

        return 'param';
    }
}
