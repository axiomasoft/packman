<?php

declare(strict_types=1);

namespace Packman\DevTools\Internal\Schemas;

use JsonException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Exceptions\SchemaException;
use Opis\JsonSchema\Validator;
use Packman\Attributes\Internal;

/** Shared Opis JSON Schema validator helper formatting pointer errors deterministically. */
#[Internal]
final class JsonSchemaValidator
{
    /** @return list<string> */
    public static function validateJsonAgainstFile(string $jsonContent, string $schemaPath): array
    {
        if (! is_file($schemaPath)) {
            return ["JSON schema file missing: {$schemaPath}"];
        }

        $schemaRaw = file_get_contents($schemaPath);

        if (! is_string($schemaRaw) || $schemaRaw === '') {
            return ["JSON schema file unreadable: {$schemaPath}"];
        }

        try {
            $schema = json_decode($schemaRaw, false, 512, JSON_THROW_ON_ERROR);
            $data = json_decode($jsonContent, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return [$exception->getMessage()];
        }

        if (! is_object($schema) && ! is_bool($schema)) {
            return ["Invalid JSON schema root: {$schemaPath}"];
        }

        try {
            $result = new Validator(max_errors: 10, stop_at_first_error: false)->validate($data, $schema);
        } catch (SchemaException $exception) {
            return ["Invalid JSON schema {$schemaPath}: {$exception->getMessage()}"];
        }

        if ($result->isValid()) {
            return [];
        }

        $error = $result->error();

        if ($error === null) {
            return ['Schema validation failed with an unknown error.'];
        }

        $formatted = (new ErrorFormatter)->format($error, multiple: false);
        $messages = [];
        foreach ($formatted as $pointer => $msg) {
            $rendered = is_string($msg) ? $msg : json_encode($msg, JSON_THROW_ON_ERROR);
            $messages[] = sprintf('%s: %s', (string) $pointer, $rendered);
        }

        return $messages;
    }
}
