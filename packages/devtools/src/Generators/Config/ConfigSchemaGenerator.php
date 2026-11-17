<?php

declare(strict_types=1);

namespace Packman\DevTools\Generators\Config;

use Packman\Attributes\Api;
use Packman\Config\Contracts\ConfigSection;
use Packman\Config\Data\CompileContext;
use Packman\DevTools\Internal\Config\SchemaRecordingReader;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Cast\Array_ as ArrayCast;
use PhpParser\Node\Expr\Cast\Bool_ as BoolCast;
use PhpParser\Node\Expr\Cast\Double as FloatCast;
use PhpParser\Node\Expr\Cast\Int_ as IntCast;
use PhpParser\Node\Expr\Cast\String_ as StringCast;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\UnaryMinus;
use PhpParser\Node\Expr\UnaryPlus;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

/**
 * Builds JSON Schema Draft 2020-12 configuration schemas from package config/*.php files and ConfigSection classes.
 */
#[Api(since: '1.0')]
final class ConfigSchemaGenerator
{
    private readonly Parser $parser;

    public function __construct()
    {
        $this->parser = (new ParserFactory)->createForHostVersion();
    }

    /**
     * @param  list<array{name: string, path: string}>  $packages
     * @return array{ok: bool, diagnostics: list<string>, changed: list<string>}
     */
    public function sync(string $rootPath, array $packages, bool $write): array
    {
        $diagnostics = [];
        $changed = [];

        foreach ($packages as $pkg) {
            $pkgDir = rtrim($rootPath, '/').'/'.trim($pkg['path'], '/');
            $schema = $this->generateForPackage($pkg['name'], $pkgDir);

            if ($schema === null) {
                continue;
            }

            $encoded = json_encode($schema, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
            $schemaFile = $pkgDir.'/resources/schemas/config.schema.json';
            $existing = is_file($schemaFile) ? file_get_contents($schemaFile) : null;

            if ($existing === $encoded) {
                continue;
            }

            if ($write) {
                $dir = dirname($schemaFile);

                if (! is_dir($dir)) {
                    mkdir($dir, 0775, true);
                }

                file_put_contents($schemaFile, $encoded);
                $changed[] = $pkg['path'].'/resources/schemas/config.schema.json';
            } else {
                $diagnostics[] = sprintf(
                    '[%s] Out-of-date config schema: %s/resources/schemas/config.schema.json (run `php bin/packman config-schema --write`).',
                    $pkg['name'],
                    $pkg['path'],
                );
            }
        }

        return [
            'ok' => $diagnostics === [],
            'diagnostics' => $diagnostics,
            'changed' => $changed,
        ];
    }

    /** @return ?array<string, mixed> */
    public function generateForPackage(string $packageName, string $packageDir): ?array
    {
        $properties = [];

        $configDir = rtrim($packageDir, '/').'/config';

        if (is_dir($configDir)) {
            $rawFiles = glob($configDir.'/*.php');
            $files = is_array($rawFiles) ? $rawFiles : [];
            sort($files, SORT_STRING);

            foreach ($files as $configFile) {
                $parsed = $this->extractSchemaFromConfigFile($configFile);

                if ($parsed !== null) {
                    $key = pathinfo($configFile, PATHINFO_FILENAME);
                    $properties[$key] = $parsed;
                }
            }
        }

        $sectionEntries = $this->extractSchemaFromSections(rtrim($packageDir, '/').'/src');

        if ($sectionEntries !== []) {
            $properties['sections'] = [
                'type' => 'object',
                'properties' => $sectionEntries,
                'additionalProperties' => false,
            ];
        }

        if ($properties === []) {
            return null;
        }

        ksort($properties, SORT_STRING);

        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => "{$packageName} configuration",
            'type' => 'object',
            'properties' => $properties,
            'additionalProperties' => false,
        ];
    }

    /** @return ?array<string, mixed> */
    private function extractSchemaFromConfigFile(string $file): ?array
    {
        $code = file_get_contents($file);

        if (! is_string($code) || $code === '') {
            return null;
        }

        $ast = $this->parser->parse($code);

        if ($ast === null) {
            return null;
        }

        $finder = new NodeFinder;
        /** @var ?Return_ $return */
        $return = $finder->findFirstInstanceOf($ast, Return_::class);

        if (! $return?->expr instanceof Array_) {
            return null;
        }

        return $this->arrayExprToSchema($return->expr);
    }

    /** @return array<string, mixed> */
    private function arrayExprToSchema(Array_ $array): array
    {
        if ($array->items === []) {
            return [
                'type' => ['array', 'object'],
            ];
        }

        $properties = [];
        $hasStringKeys = false;

        foreach ($array->items as $item) {
            if (! $item->key instanceof String_) {
                continue;
            }

            $hasStringKeys = true;
            $properties[$item->key->value] = $this->itemExprToSchema($item);
        }

        if (! $hasStringKeys) {
            return [
                'type' => 'array',
            ];
        }

        ksort($properties, SORT_STRING);

        return [
            'type' => 'object',
            'properties' => $properties,
            'additionalProperties' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function itemExprToSchema(ArrayItem $item): array
    {
        $doc = $item->getDocComment()?->getText();

        if ($item->value instanceof Array_ && $item->value->items === []) {
            if (is_string($doc) && preg_match('/@var\s+array<string,\s*bool>/', $doc) === 1) {
                return [
                    'anyOf' => [
                        [
                            'type' => 'array',
                            'maxItems' => 0,
                        ],
                        [
                            'type' => 'object',
                            'additionalProperties' => [
                                'type' => 'boolean',
                            ],
                        ],
                    ],
                ];
            }
        }

        if ($item->value instanceof ConstFetch && strtolower($item->value->name->toString()) === 'null' && is_string($doc)) {
            if (preg_match('/@var\s+\?(?:list|array)\b|@var\s+(?:list|array)<[^>]*>\s*\|\s*null|@var\s+null\s*\|\s*(?:list|array)/', $doc) === 1) {
                return ['type' => ['array', 'null'], 'default' => null];
            }

            if (preg_match('/@var\s+\?int\b|@var\s+int\s*\|\s*null|@var\s+null\s*\|\s*int\b/', $doc) === 1) {
                return ['type' => ['integer', 'null'], 'default' => null];
            }

            if (preg_match('/@var\s+\?bool\b|@var\s+bool\s*\|\s*null|@var\s+null\s*\|\s*bool\b/', $doc) === 1) {
                return ['type' => ['boolean', 'null'], 'default' => null];
            }
        }

        return $this->exprToSchema($item->value);
    }

    /** @return array<string, mixed> */
    private function exprToSchema(Expr $expr): array
    {
        if ($expr instanceof Array_) {
            return $this->arrayExprToSchema($expr);
        }

        if ($expr instanceof String_) {
            return ['type' => 'string', 'default' => $expr->value];
        }

        if ($expr instanceof Int_) {
            return ['type' => 'integer', 'default' => $expr->value];
        }

        if ($expr instanceof Float_) {
            return ['type' => 'number', 'default' => $expr->value];
        }

        if ($expr instanceof UnaryMinus) {
            if ($expr->expr instanceof Int_) {
                return ['type' => 'integer', 'default' => -$expr->expr->value];
            }

            if ($expr->expr instanceof Float_) {
                return ['type' => 'number', 'default' => -$expr->expr->value];
            }
        }

        if ($expr instanceof UnaryPlus) {
            if ($expr->expr instanceof Int_) {
                return ['type' => 'integer', 'default' => $expr->expr->value];
            }

            if ($expr->expr instanceof Float_) {
                return ['type' => 'number', 'default' => $expr->expr->value];
            }
        }

        if ($expr instanceof BoolCast) {
            return $this->castSchema('boolean', $expr->expr, static fn (mixed $v): bool => (bool) $v);
        }

        if ($expr instanceof IntCast) {
            return $this->castSchema('integer', $expr->expr, static fn (mixed $v): int => is_numeric($v) || is_bool($v) ? (int) $v : 0);
        }

        if ($expr instanceof FloatCast) {
            return $this->castSchema('number', $expr->expr, static fn (mixed $v): float => is_numeric($v) || is_bool($v) ? (float) $v : 0.0);
        }

        if ($expr instanceof StringCast) {
            return $this->castSchema('string', $expr->expr, static fn (mixed $v): string => is_scalar($v) ? (string) $v : '');
        }

        if ($expr instanceof ArrayCast) {
            return ['type' => ['array', 'object']];
        }

        if ($expr instanceof ConstFetch) {
            $name = strtolower($expr->name->toString());

            return match ($name) {
                'true' => ['type' => 'boolean', 'default' => true],
                'false' => ['type' => 'boolean', 'default' => false],
                'null' => ['type' => ['string', 'null'], 'default' => null],
                default => ['type' => ['string', 'integer', 'number', 'boolean', 'null']],
            };
        }

        if ($expr instanceof FuncCall && $expr->name instanceof Name) {
            $fn = strtolower($expr->name->toString());

            if ($fn === 'env') {
                if (isset($expr->args[1]) && $expr->args[1] instanceof Arg) {
                    return $this->exprToSchema($expr->args[1]->value);
                }

                return [
                    'type' => ['string', 'integer', 'number', 'boolean', 'null'],
                    'default' => null,
                ];
            }
        }

        return [
            'type' => ['string', 'integer', 'number', 'boolean', 'array', 'object', 'null'],
        ];
    }

    /**
     * @param  callable(mixed): mixed  $coerce
     * @return array<string, mixed>
     */
    private function castSchema(string $jsonType, Expr $innerExpr, callable $coerce): array
    {
        $inner = $this->exprToSchema($innerExpr);
        $schema = ['type' => $jsonType];

        if (array_key_exists('default', $inner) && ($inner['default'] === null || is_scalar($inner['default']))) {
            $schema['default'] = $coerce($inner['default']);
        }

        return $schema;
    }

    /** @return array<string, mixed> */
    private function extractSchemaFromSections(string $srcDir): array
    {
        if (! is_dir($srcDir)) {
            return [];
        }

        $sectionClasses = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir));
        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $code = file_get_contents($file->getPathname());

            if (! is_string($code) || ! str_contains($code, 'ConfigSection')) {
                continue;
            }

            $ast = $this->parser->parse($code);

            if ($ast === null) {
                continue;
            }

            $traverser = new NodeTraverser;
            $traverser->addVisitor(new NameResolver);
            /** @var list<Node\Stmt> $resolved */
            $resolved = $traverser->traverse($ast);

            /** @var list<Class_> $classes */
            $classes = (new NodeFinder)->findInstanceOf($resolved, Class_::class);
            foreach ($classes as $class) {
                if ($class->isAbstract()) {
                    continue;
                }

                foreach ($class->implements as $iface) {
                    if ($iface->toString() === ConfigSection::class) {
                        $fqcn = $class->namespacedName?->toString();

                        if (is_string($fqcn)) {
                            $sectionClasses[] = $fqcn;
                        }
                    }
                }
            }
        }

        $instances = [];
        foreach ($sectionClasses as $className) {
            if (! class_exists($className)) {
                continue;
            }

            $ref = new ReflectionClass($className);
            $ctor = $ref->getConstructor();

            if ($ctor !== null && $ctor->getNumberOfRequiredParameters() > 0) {
                continue;
            }

            $instance = $ref->newInstance();

            if ($instance instanceof ConfigSection) {
                $instances[] = $instance;
            }
        }

        if ($instances === []) {
            return [];
        }

        $entries = [];
        $context = new CompileContext(
            environment: 'testing',
            defaultConnection: 'sqlite',
            connections: ['sqlite'],
            userModelFallback: null,
            allowUnsafe: false,
        );
        foreach ($instances as $instance) {
            $reader = new SchemaRecordingReader($instance->key(), $context);
            $instance->compile($reader);
            foreach ($reader->schema as $path => $entry) {
                $entries[$path] = $entry;
            }
        }

        ksort($entries, SORT_STRING);

        return $entries;
    }
}
