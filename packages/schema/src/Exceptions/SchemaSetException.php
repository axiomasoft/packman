<?php

declare(strict_types=1);

namespace Packman\Schema\Exceptions;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\CoreErrorCode;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Errors\Exceptions\CodedException;

#[Spi(kind: SpiKind::Call, since: '1.0')]
final class SchemaSetException extends CodedException
{
    private function __construct(string $message, private readonly CoreErrorCode $errorCode)
    {
        parent::__construct($message);
    }

    public static function invalidSet(string $id): self
    {
        return new self(message: "invalid_set: Invalid schema set [$id].", errorCode: CoreErrorCode::SchemaInvalidSet);
    }

    public static function duplicateSet(string $id): self
    {
        return new self(message: "duplicate_set: Schema set [$id] is declared twice.", errorCode: CoreErrorCode::SchemaDuplicateSet);
    }

    public static function duplicateMigration(string $name, string $first, string $second): self
    {
        return new self(message: "duplicate_migration: Migration [$name] belongs to [$first] and [$second].", errorCode: CoreErrorCode::SchemaDuplicateMigration);
    }

    public static function unknownSet(string $id): self
    {
        return new self(message: "unknown_set: Schema set [$id] is unavailable.", errorCode: CoreErrorCode::SchemaUnknownSet);
    }

    public static function unsupportedDriver(string $id, string $driver): self
    {
        return new self(message: "unsupported_driver: Schema set [$id] does not support [$driver].", errorCode: CoreErrorCode::SchemaUnsupportedDriver);
    }

    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(code: $this->errorCode, category: ErrorCategory::Internal, retryable: false);
    }
}
