<?php

declare(strict_types=1);

namespace Packman\DevTools\Gates\Contracts;

use JsonException;
use Opis\JsonSchema\Exceptions\SchemaException;
use Opis\JsonSchema\JsonPointer;
use Opis\JsonSchema\Schema;
use Opis\JsonSchema\Schemas\ExceptionSchema;
use Opis\JsonSchema\Uri;
use Opis\JsonSchema\Validator;
use Packman\Attributes\Api;
use Packman\DevTools\Contracts\Gates\QualityGate;
use Packman\DevTools\Data\Gates\GateContext;
use Packman\DevTools\Data\Gates\GateResult;
use Packman\DevTools\Internal\Schemas\JsonSchemaValidator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Validates JSON schemas, packman.json against packman.schema.json, and monotonic migration timestamps.
 */
#[Api(since: '1.0')]
final class SchemaGate implements QualityGate
{
    public function id(): string
    {
        return 'schema-gate';
    }

    public function category(): string
    {
        return 'contracts';
    }

    public function description(): string
    {
        return 'Validates packman.json schema compliance, JSON schema syntax integrity, and migration timestamp uniqueness.';
    }

    public function run(GateContext $context): GateResult
    {
        $violations = [];
        $root = rtrim($context->rootPath, '/');

        $manifestPath = $root.'/packman.json';
        $manifestSchemaPath = $root.'/schemas/packman.schema.json';

        if (! is_file($manifestSchemaPath)) {
            $manifestSchemaPath = dirname(__DIR__, 3).'/resources/schemas/packman.schema.json';
        }

        if (is_file($manifestPath) && is_file($manifestSchemaPath)) {
            $manifestRaw = file_get_contents($manifestPath);

            if (! is_string($manifestRaw)) {
                $violations[] = 'packman.json: unable to read manifest.';
            } else {
                foreach (JsonSchemaValidator::validateJsonAgainstFile($manifestRaw, $manifestSchemaPath) as $err) {
                    $violations[] = "packman.json: {$err}";
                }
            }
        }

        foreach ($this->collectJsonFiles($root.'/schemas') as $jsonFile) {
            $this->assertValidJsonSyntax($jsonFile, $root, $violations);
        }

        foreach ($context->config->packages as $package) {
            $pkgDir = $context->packageDirectory($package);
            $composerPath = $pkgDir.'/composer.json';

            if (is_file($composerPath)) {
                $this->assertValidJsonSyntax($composerPath, $root, $violations);
            }

            foreach ($this->collectJsonFiles($pkgDir.'/resources') as $resourceJson) {
                $this->assertValidJsonSyntax($resourceJson, $root, $violations);
            }

            $migrationsDir = $pkgDir.'/database/migrations';

            if (is_dir($migrationsDir)) {
                $seenPrefixes = [];
                $rawFiles = glob($migrationsDir.'/*.php');
                $files = is_array($rawFiles) ? $rawFiles : [];
                sort($files, SORT_STRING);
                foreach ($files as $migrationFile) {
                    $base = basename($migrationFile);

                    if (preg_match('/^(\d{4}_\d{2}_\d{2}_\d{6})_([a-z0-9_]+)\.php$/', $base, $matches) !== 1) {
                        $violations[] = "[{$package->name}] Migration filename does not follow YYYY_MM_DD_NNNNNN_name.php: {$base}";

                        continue;
                    }

                    $prefix = $matches[1];

                    if (isset($seenPrefixes[$prefix])) {
                        $violations[] = "[{$package->name}] Duplicate migration timestamp prefix {$prefix} ({$seenPrefixes[$prefix]} and {$base}).";
                    }

                    $seenPrefixes[$prefix] = $base;
                }
            }
        }

        return $violations === []
            ? GateResult::pass($this->id(), $this->category(), ['Monorepo schemas, JSON contracts, and migration timestamps are valid.'])
            : GateResult::fail($this->id(), $this->category(), $violations);
    }

    /** @return list<string> */
    private function collectJsonFiles(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'json') {
                $files[] = $file->getPathname();
            }
        }

        sort($files, SORT_STRING);

        return $files;
    }

    /** @param list<string> $violations */
    private function assertValidJsonSyntax(string $path, string $root, array &$violations): void
    {
        $raw = file_get_contents($path);

        if (! is_string($raw) || trim($raw) === '') {
            $violations[] = 'Empty or unreadable JSON file: '.ltrim(str_replace($root, '', $path), '/');

            return;
        }

        try {
            json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            if (str_ends_with($path, '.schema.json')) {
                $this->assertValidJsonSchema($raw, $path, $root, $violations);
            }
        } catch (JsonException $exception) {
            $rel = ltrim(str_replace($root, '', $path), '/');
            $violations[] = sprintf('%s: invalid JSON (%s)', $rel, $exception->getMessage());
        }
    }

    /** @param list<string> $violations */
    private function assertValidJsonSchema(string $raw, string $path, string $root, array &$violations): void
    {
        $rel = ltrim(str_replace($root, '', $path), '/');
        $decoded = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);

        if (! is_object($decoded) && ! is_bool($decoded)) {
            $violations[] = sprintf('%s: root JSON schema must be an object or boolean.', $rel);

            return;
        }

        if (is_bool($decoded)) {
            return;
        }

        $validator = new Validator;

        try {
            $rootSchema = $validator->loader()->loadObjectSchema($decoded);
            $this->assertEagerSchemaValid($rootSchema, $validator);
        } catch (SchemaException $exception) {
            $violations[] = sprintf('%s: invalid JSON schema (%s)', $rel, $exception->getMessage());

            return;
        }

        $rootId = $rootSchema->info()->id();
        $this->assertValidSubschemas($decoded, '#', [], $rel, $violations, $validator, $rootId);
    }

    /**
     * @param  list<string|int>  $path
     * @param  list<string>  $violations
     */
    private function assertValidSubschemas(
        mixed $node,
        string $pointer,
        array $path,
        string $rel,
        array &$violations,
        Validator $validator,
        ?Uri $rootId,
    ): void {
        if (! is_object($node)) {
            return;
        }

        foreach (['properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas'] as $mapKeyword) {
            if (! property_exists($node, $mapKeyword)) {
                continue;
            }

            $map = $node->{$mapKeyword};

            if (! is_object($map)) {
                $violations[] = sprintf('%s (%s/%s): expected JSON object map of subschemas.', $rel, $pointer, $mapKeyword);

                continue;
            }

            foreach (get_object_vars($map) as $key => $subschema) {
                $childPointer = $pointer.'/'.$mapKeyword.'/'.$key;
                $childPath = [...$path, $mapKeyword, (string) $key];

                if (! is_object($subschema) && ! is_bool($subschema)) {
                    $violations[] = sprintf('%s (%s): subschema must be an object or boolean.', $rel, $childPointer);

                    continue;
                }

                $this->validateSubschemaNode($subschema, $childPointer, $childPath, $rel, $violations, $validator, $rootId);
            }
        }

        foreach (['additionalProperties', 'additionalItems', 'unevaluatedProperties', 'unevaluatedItems', 'contains', 'propertyNames', 'not', 'if', 'then', 'else', 'contentSchema'] as $singleKeyword) {
            if (! property_exists($node, $singleKeyword)) {
                continue;
            }

            $subschema = $node->{$singleKeyword};
            $childPointer = $pointer.'/'.$singleKeyword;
            $childPath = [...$path, $singleKeyword];

            if (! is_object($subschema) && ! is_bool($subschema)) {
                $violations[] = sprintf('%s (%s): subschema must be an object or boolean.', $rel, $childPointer);

                continue;
            }

            $this->validateSubschemaNode($subschema, $childPointer, $childPath, $rel, $violations, $validator, $rootId);
        }

        if (property_exists($node, 'items')) {
            $items = $node->items;
            $itemsPointer = $pointer.'/items';

            if (is_array($items)) {
                foreach ($items as $idx => $subschema) {
                    $childPointer = $itemsPointer.'/'.$idx;
                    $childPath = [...$path, 'items', $idx];

                    if (! is_object($subschema) && ! is_bool($subschema)) {
                        $violations[] = sprintf('%s (%s): subschema must be an object or boolean.', $rel, $childPointer);

                        continue;
                    }

                    $this->validateSubschemaNode($subschema, $childPointer, $childPath, $rel, $violations, $validator, $rootId);
                }
            } elseif (is_object($items) || is_bool($items)) {
                $this->validateSubschemaNode($items, $itemsPointer, [...$path, 'items'], $rel, $violations, $validator, $rootId);
            } else {
                $violations[] = sprintf('%s (%s): items must be a subschema or array of subschemas.', $rel, $itemsPointer);
            }
        }

        foreach (['allOf', 'anyOf', 'oneOf', 'prefixItems'] as $listKeyword) {
            if (! property_exists($node, $listKeyword)) {
                continue;
            }

            $list = $node->{$listKeyword};
            $listPointer = $pointer.'/'.$listKeyword;

            if (! is_array($list) || $list === []) {
                $violations[] = sprintf('%s (%s): expected non-empty array of subschemas.', $rel, $listPointer);

                continue;
            }

            foreach ($list as $idx => $subschema) {
                $childPointer = $listPointer.'/'.$idx;
                $childPath = [...$path, $listKeyword, $idx];

                if (! is_object($subschema) && ! is_bool($subschema)) {
                    $violations[] = sprintf('%s (%s): subschema must be an object or boolean.', $rel, $childPointer);

                    continue;
                }

                $this->validateSubschemaNode($subschema, $childPointer, $childPath, $rel, $violations, $validator, $rootId);
            }
        }

        if (property_exists($node, 'dependencies')) {
            $dependencies = $node->dependencies;

            if (! is_object($dependencies)) {
                $violations[] = sprintf('%s (%s/dependencies): expected JSON object map.', $rel, $pointer);

                return;
            }

            foreach (get_object_vars($dependencies) as $key => $entry) {
                $childPointer = $pointer.'/dependencies/'.$key;
                $childPath = [...$path, 'dependencies', (string) $key];

                if (is_array($entry)) {
                    foreach ($entry as $dep) {
                        if (! is_string($dep)) {
                            $violations[] = sprintf('%s (%s): property dependency entries must be strings.', $rel, $childPointer);

                            break;
                        }
                    }

                    continue;
                }

                if (! is_object($entry) && ! is_bool($entry)) {
                    $violations[] = sprintf('%s (%s): dependency must be a subschema or array of property names.', $rel, $childPointer);

                    continue;
                }

                $this->validateSubschemaNode($entry, $childPointer, $childPath, $rel, $violations, $validator, $rootId);
            }
        }
    }

    /**
     * @param  list<string|int>  $path
     * @param  list<string>  $violations
     */
    private function validateSubschemaNode(
        object|bool $subschema,
        string $pointer,
        array $path,
        string $rel,
        array &$violations,
        Validator $validator,
        ?Uri $rootId,
    ): void {
        if (! is_object($subschema)) {
            return;
        }

        if ($rootId instanceof Uri) {
            $fragment = JsonPointer::pathToFragment($path);
            $subUri = Uri::merge($fragment, $rootId, true);

            if ($subUri instanceof Uri) {
                try {
                    $loaded = $validator->loader()->loadSchemaById($subUri);

                    if ($loaded instanceof Schema) {
                        $this->assertEagerSchemaValid($loaded, $validator);
                    }
                } catch (SchemaException $exception) {
                    $violations[] = sprintf('%s (%s): invalid JSON schema (%s)', $rel, $pointer, $exception->getMessage());
                }
            }
        }

        $this->assertValidSubschemas($subschema, $pointer, $path, $rel, $violations, $validator, $rootId);
    }

    /**
     * Opis SchemaParser wraps keyword SchemaExceptions in an ExceptionSchema; force eager realization.
     *
     * @throws SchemaException
     */
    private function assertEagerSchemaValid(Schema $schema, Validator $validator): void
    {
        if ($schema instanceof ExceptionSchema) {
            $schema->validate($validator->createContext(null));
        }
    }
}
