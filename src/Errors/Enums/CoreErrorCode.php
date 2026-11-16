<?php

declare(strict_types=1);

namespace Packman\Errors\Enums;

use Packman\Attributes\Api;
use Packman\Errors\Contracts\ErrorCode;

#[Api(since: '1.0')]
enum CoreErrorCode: string implements ErrorCode
{
    case DuplicateCode = 'packman.duplicate_code';
    case DuplicateType = 'packman.duplicate_type';
    case ForeignPrefix = 'packman.foreign_prefix';
    case InternalError = 'packman.internal_error';
    case InvalidConfiguration = 'packman.invalid_configuration';
    case InvalidDefinition = 'packman.invalid_definition';
    case InvalidScopePayload = 'packman.invalid_scope_payload';
    case InvalidStatus = 'packman.invalid_status';
    case RegistryFrozen = 'packman.registry_frozen';
    case ScopeUnavailable = 'packman.scope_unavailable';
    case UnknownCode = 'packman.unknown_code';
    case UnsupportedScope = 'packman.unsupported_scope';
    case SchemaInvalidSet = 'packman.schema_invalid_set';
    case SchemaDuplicateSet = 'packman.schema_duplicate_set';
    case SchemaDuplicateMigration = 'packman.schema_duplicate_migration';
    case SchemaUnknownSet = 'packman.schema_unknown_set';
    case SchemaUnsupportedDriver = 'packman.schema_unsupported_driver';

    public function value(): string
    {
        return $this->value;
    }
}
