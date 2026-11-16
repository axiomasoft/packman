<?php

declare(strict_types=1);

namespace Packman\Modules\Exceptions\Compiler;

use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Errors\Exceptions\CodedException;
use Packman\Modules\Contracts\Definition\ModuleDefinition;
use Packman\Modules\Enums\ModuleErrorCode;

#[Api(since: '1.0')]
final class InvalidManifestException extends CodedException
{
    public static function invalidComposerName(string $composerName): self
    {
        return new self(sprintf(
            'Module manifest composer name [%s] is invalid — expected "vendor/package".',
            $composerName,
        ));
    }

    public static function emptyTitle(string $composerName): self
    {
        return new self(sprintf('Module [%s] manifest is missing a title.', $composerName));
    }

    public static function invalidTablePrefix(string $composerName, string $prefix): self
    {
        return new self(sprintf(
            'Module [%s] table prefix [%s] is invalid — expected 2-3 lowercase letters ([a-z]{2,3}), D9.',
            $composerName,
            $prefix,
        ));
    }

    public static function emptyReplacement(string $composerName, string $field): self
    {
        return new self(sprintf(
            'Module [%s] replacement [%s] must be a non-empty string.',
            $composerName,
            $field,
        ));
    }

    public static function notAModuleDefinition(string $class): self
    {
        return new self(sprintf(
            'Discovered module class [%s] does not extend %s.',
            $class,
            ModuleDefinition::class,
        ));
    }

    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(code: ModuleErrorCode::InvalidManifestException, category: ErrorCategory::Validation, retryable: false);
    }
}
