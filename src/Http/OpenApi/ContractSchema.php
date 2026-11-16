<?php

declare(strict_types=1);

namespace Packman\Testkit\Http\OpenApi;

use JsonException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Packman\Attributes\Api;
use Packman\Testkit\Exceptions\Http\ContractAssertionException;
use PHPUnit\Framework\Assert;

/**
 * Validates decoded payloads or JSON strings against JSON Schema Draft 2020-12 / Draft-07 files via opis/json-schema.
 */
#[Api(since: '1.0')]
final class ContractSchema
{
    public static function assertMatchesFile(mixed $payload, string $schemaPath): void
    {
        if (! is_file($schemaPath)) {
            throw new ContractAssertionException("JSON schema file does not exist: {$schemaPath}");
        }

        $rawSchema = file_get_contents($schemaPath);

        if (! is_string($rawSchema) || $rawSchema === '') {
            throw new ContractAssertionException("Failed to read JSON schema file: {$schemaPath}");
        }

        $schemaObject = json_decode($rawSchema, false, 512, JSON_THROW_ON_ERROR);

        if (! is_object($schemaObject) && ! is_bool($schemaObject)) {
            throw new ContractAssertionException("Schema file must decode to a JSON object or boolean: {$schemaPath}");
        }

        self::assertMatchesSchema($payload, $schemaObject, basename($schemaPath));
    }

    public static function assertMatchesSchema(mixed $payload, object|bool $schema, string $label = 'contract'): void
    {
        $normalized = self::toJsonValue($payload);
        $validator = new Validator(max_errors: 10, stop_at_first_error: false);
        $result = $validator->validate($normalized, $schema);

        if ($result->isValid()) {
            Assert::assertNull($result->error());

            return;
        }

        $error = $result->error();
        $formatted = $error !== null
            ? (new ErrorFormatter)->format($error, multiple: true)
            : ['error' => 'unknown schema mismatch'];

        Assert::fail(sprintf(
            'Payload did not match %s schema: %s',
            $label,
            json_encode($formatted, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ));
    }

    /** @throws JsonException */
    public static function toJsonValue(mixed $payload): mixed
    {
        if (is_string($payload)) {
            $trimmed = trim($payload);

            if ($trimmed !== '' && (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '['))) {
                return json_decode($trimmed, false, 512, JSON_THROW_ON_ERROR);
            }

            return $payload;
        }

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return json_decode($encoded, false, 512, JSON_THROW_ON_ERROR);
    }
}
