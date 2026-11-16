<?php

declare(strict_types=1);

use Packman\Testkit\Exceptions\Http\ContractAssertionException;
use Packman\Testkit\Http\OpenApi\ContractSchema;
use Packman\Testkit\Http\OpenApi\OpenApiResponse;
use PHPUnit\Framework\AssertionFailedError;

it('validates payloads against inline JSON schemas and files cleanly', function (): void {
    $schemaPath = sys_get_temp_dir().'/packman-contract-schema-'.bin2hex(random_bytes(6)).'.json';
    file_put_contents($schemaPath, json_encode([
        '$schema' => 'https://json-schema.org/draft/2020-12/schema',
        'type' => 'object',
        'required' => ['id', 'ok'],
        'additionalProperties' => false,
        'properties' => [
            'id' => ['type' => 'string'],
            'ok' => ['type' => 'boolean'],
        ],
    ], JSON_THROW_ON_ERROR));

    try {
        ContractSchema::assertMatchesFile(['id' => 'pkg-1', 'ok' => true], $schemaPath);

        expect(static fn () => ContractSchema::assertMatchesFile(['id' => 42], $schemaPath))
            ->toThrow(AssertionFailedError::class);
    } finally {
        @unlink($schemaPath);
    }
});

it('validates OpenAPI path responses including #/components/schemas references', function (): void {
    $spec = OpenApiResponse::fromArray([
        'openapi' => '3.1.0',
        'paths' => [
            '/health' => [
                'get' => [
                    'responses' => [
                        '200' => [
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/HealthStatus'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'components' => [
            'schemas' => [
                'HealthStatus' => [
                    'type' => 'object',
                    'required' => ['status'],
                    'properties' => [
                        'status' => ['type' => 'string', 'enum' => ['ok', 'degraded']],
                    ],
                ],
            ],
        ],
    ]);

    $spec->assertMatches('GET', '/health', 200, '{"status":"ok"}');

    expect(static fn () => $spec->assertMatches('GET', '/health', 200, ['status' => 'broken']))
        ->toThrow(AssertionFailedError::class)
        ->and(static fn () => $spec->assertMatches('POST', '/missing', 200, []))
        ->toThrow(ContractAssertionException::class);
});
