<?php

declare(strict_types=1);

namespace Packman\Testkit\Http\OpenApi;

use Illuminate\Testing\TestResponse;
use Packman\Attributes\Api;
use Packman\Testkit\Exceptions\Http\ContractAssertionException;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Yaml\Yaml;

/**
 * Validates HTTP responses (Laravel TestResponse or raw array/JSON) against OpenAPI 3.x path response schemas.
 */
#[Api(since: '1.0')]
final readonly class OpenApiResponse
{
    /** @param array<string, mixed> $document */
    private function __construct(private array $document) {}

    public static function fromFile(string $specPath): self
    {
        if (! is_file($specPath)) {
            throw new ContractAssertionException("OpenAPI specification file does not exist: {$specPath}");
        }

        $raw = file_get_contents($specPath);

        if (! is_string($raw) || trim($raw) === '') {
            throw new ContractAssertionException("OpenAPI specification is empty: {$specPath}");
        }

        $lower = strtolower($specPath);

        if (str_ends_with($lower, '.json')) {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } elseif (class_exists(Yaml::class)) {
            $decoded = Yaml::parse($raw);
        } else {
            throw new ContractAssertionException('symfony/yaml is required to parse non-JSON OpenAPI files.');
        }

        if (! is_array($decoded)) {
            throw new ContractAssertionException("OpenAPI specification did not parse to an associative array: {$specPath}");
        }

        /** @var array<string, mixed> $decoded */
        return new self($decoded);
    }

    /** @param array<string, mixed> $document */
    public static function fromArray(array $document): self
    {
        return new self($document);
    }

    /**
     * @param  TestResponse<Response>|Response|array<mixed>|string  $response
     */
    public function assertMatches(
        string $method,
        string $path,
        int $statusCode,
        mixed $response,
        string $mediaType = 'application/json',
    ): void {
        if ($response instanceof TestResponse) {
            $base = $response->baseResponse;
            Assert::assertSame($statusCode, $base->getStatusCode(), "Expected HTTP {$statusCode} for {$method} {$path}.");
            $payload = $base->getContent();
        } elseif ($response instanceof Response) {
            Assert::assertSame($statusCode, $response->getStatusCode(), "Expected HTTP {$statusCode} for {$method} {$path}.");
            $payload = $response->getContent();
        } else {
            $payload = $response;
        }

        $schemaArray = $this->resolveResponseSchema($method, $path, $statusCode, $mediaType);
        $resolved = $this->inlineInternalRefs($schemaArray);
        $schemaObject = json_decode(json_encode($resolved, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);

        if (! is_object($schemaObject) && ! is_bool($schemaObject)) {
            throw new ContractAssertionException("Resolved OpenAPI schema for {$method} {$path} ({$statusCode}) is invalid.");
        }

        ContractSchema::assertMatchesSchema(
            $payload === false ? '' : $payload,
            $schemaObject,
            sprintf('OpenAPI %s %s [%d]', strtoupper($method), $path, $statusCode),
        );
    }

    /** @return array<string, mixed> */
    private function resolveResponseSchema(string $method, string $path, int $statusCode, string $mediaType): array
    {
        $paths = $this->document['paths'] ?? null;

        if (! is_array($paths) || ! is_array($paths[$path] ?? null)) {
            throw new ContractAssertionException("OpenAPI path [{$path}] is not defined.");
        }

        $pathItem = $paths[$path];
        $lowerMethod = strtolower($method);

        if (! is_array($pathItem[$lowerMethod] ?? null)) {
            throw new ContractAssertionException("OpenAPI method [{$lowerMethod}] is not defined on [{$path}].");
        }

        $operation = $pathItem[$lowerMethod];
        $responses = $operation['responses'] ?? null;

        if (! is_array($responses)) {
            throw new ContractAssertionException("OpenAPI operation [{$lowerMethod} {$path}] defines no responses.");
        }

        $statusKey = (string) $statusCode;
        $responseSpec = $responses[$statusKey] ?? $responses['default'] ?? null;

        if (! is_array($responseSpec)) {
            throw new ContractAssertionException("OpenAPI status [{$statusKey}] is not defined on [{$lowerMethod} {$path}].");
        }

        $content = $responseSpec['content'] ?? null;

        if (! is_array($content) || ! is_array($content[$mediaType] ?? null)) {
            throw new ContractAssertionException("OpenAPI media type [{$mediaType}] is not defined on [{$lowerMethod} {$path} {$statusKey}].");
        }

        $schema = $content[$mediaType]['schema'] ?? null;

        if (! is_array($schema)) {
            throw new ContractAssertionException("OpenAPI schema missing for [{$lowerMethod} {$path} {$statusKey} {$mediaType}].");
        }

        /** @var array<string, mixed> $schema */
        return $schema;
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @param  array<string, bool>  $visited
     * @return array<array-key, mixed>
     */
    private function inlineInternalRefs(array $node, array $visited = []): array
    {
        $ref = $node['$ref'] ?? null;

        if (is_string($ref) && str_starts_with($ref, '#/')) {
            if (isset($visited[$ref])) {
                return [];
            }

            $visited[$ref] = true;
            $target = $this->followPointer(substr($ref, 2));
            unset($node['$ref']);

            return $this->inlineInternalRefs(array_replace($target, $node), $visited);
        }

        $out = [];
        foreach ($node as $key => $value) {
            $out[$key] = is_array($value) ? $this->inlineInternalRefs($value, $visited) : $value;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function followPointer(string $pointer): array
    {
        $cursor = $this->document;
        foreach (explode('/', $pointer) as $segment) {
            $decoded = str_replace(['~1', '~0'], ['/', '~'], $segment);

            if (! is_array($cursor) || ! array_key_exists($decoded, $cursor)) {
                throw new ContractAssertionException("Unresolvable OpenAPI JSON pointer [#/{$pointer}].");
            }

            $cursor = $cursor[$decoded];
        }

        if (! is_array($cursor)) {
            throw new ContractAssertionException("OpenAPI JSON pointer [#/{$pointer}] did not resolve to a schema object.");
        }

        /** @var array<string, mixed> $cursor */
        return $cursor;
    }
}
