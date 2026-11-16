<?php

declare(strict_types=1);

namespace Packman\DevTools\Scanners\Surface;

use JsonException;
use Packman\Attributes\Api;
use Packman\DevTools\Data\Surface\SurfaceReport;
use Packman\DevTools\Internal\Schemas\JsonSchemaValidator;

/** Encodes and schema-validates php-api/1 manifest documents. */
#[Api(since: '1.0')]
final class PhpApiDocument
{
    /** @throws JsonException */
    public static function encode(SurfaceReport $report): string
    {
        return json_encode(
            $report->toManifestArray(),
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        )."\n";
    }

    /** @return list<string> */
    public static function validateJsonAgainstSchema(string $jsonContent, string $schemaPath): array
    {
        return JsonSchemaValidator::validateJsonAgainstFile($jsonContent, $schemaPath);
    }
}
