<?php

declare(strict_types=1);

namespace Packman\Modules\Exceptions;

use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Errors\Exceptions\CodedException;
use Packman\Modules\Enums\ModuleErrorCode;

/**
 * Compilation validation failure: always a deploy failure, never a warning.
 */
#[Api(since: '1.0')]
final class CompilationException extends CodedException
{
    public static function duplicateTablePrefix(string $prefix, string $firstModule, string $secondModule): self
    {
        return new self(sprintf(
            'Table prefix [%s] is claimed by both [%s] and [%s] — tablePrefix must be unique across the deployment (D9).',
            $prefix,
            $firstModule,
            $secondModule,
        ));
    }

    public static function unmetRequirement(string $composerName, string $missingDependency): self
    {
        return new self(sprintf(
            'Module [%s] requires [%s], which is not installed in this deployment (requires must mirror composer, B-10 AC-2).',
            $composerName,
            $missingDependency,
        ));
    }

    public static function unknownExtender(string $composerName, string $class): self
    {
        return new self(sprintf(
            'Module [%s] declares extender [%s], which is outside the closed set of 8 Packman\\Modules\\Extend\\* classes.',
            $composerName,
            $class,
        ));
    }

    public static function unknownEntityHandle(string $composerName, string $entityHandle): self
    {
        return new self(sprintf(
            'Module [%s] declares an EntityField extender targeting entity handle [%s], which no compiled manifest registers.',
            $composerName,
            $entityHandle,
        ));
    }

    public static function tablePrefixMismatch(string $composerName, string $table, string $expectedPrefix): self
    {
        return new self(sprintf(
            'Module [%s] declares Entity table [%s], which does not start with its own table prefix [%s_] (D9).',
            $composerName,
            $table,
            $expectedPrefix,
        ));
    }

    public static function missingTablePrefix(string $composerName): self
    {
        return new self(sprintf(
            'Module [%s] manifest is missing tablePrefix() — mandatory as of D37, no auto-derivation from the '
            .'composer name (that was the D9 hole: a third-party module could legally land on a reserved prefix).',
            $composerName,
        ));
    }

    /**
     * Distinct from {@see missingTablePrefix} on purpose: that one is the
     * VALIDATION-stage rejection (validateRequiredFields); this one fires from
     * RegistryCompiler's defensive `?? throw` guards, which are unreachable once
     * validation has run. Reaching it means validateRequiredFields() was skipped
     * — a broken invariant, not a bad manifest — so the message says so loudly
     * instead of masquerading as the ordinary "missing tablePrefix()" rejection.
     * The two carrying different text is what lets a test pin the validation
     * layer specifically (a mutant that removes the validation check falls
     * through to this distinct throw and the validation-message assert reddens).
     */
    public static function tablePrefixInvariantBypassed(string $composerName): self
    {
        return new self(sprintf(
            'Module [%s] reached compile with a null tablePrefix — validateRequiredFields() bypassed. '
            .'tablePrefix is mandatory (D37) and validation must reject a prefix-less manifest before the '
            .'pipeline runs; hitting this defensive guard means that invariant was broken.',
            $composerName,
        ));
    }

    public static function missingTitle(string $composerName): self
    {
        return new self(sprintf(
            'Module [%s] manifest is missing title() — required at compile time, not just rejected as empty by '
            .'the setter (a manifest that never calls title() at all was shipping title: null into the registry).',
            $composerName,
        ));
    }

    public static function duplicateEntityHandle(string $handle, string $firstModule, string $secondModule): self
    {
        return new self(sprintf(
            'Entity handle [%s] is declared by both [%s] and [%s] — the first module\'s EntityDefinition would be '
            .'silently overwritten. Entity handles must be unique across the compiled deployment.',
            $handle,
            $firstModule,
            $secondModule,
        ));
    }

    public static function duplicateIdentity(string $point, string $id, string $firstOwner, string $secondOwner): self
    {
        return new self(sprintf(
            'Duplicate identity [%s:%s] is declared by both [%s] and [%s].',
            $point,
            $id,
            $firstOwner,
            $secondOwner,
        ));
    }

    public static function replacementTargetMissing(string $point, string $targetId, string $owner): self
    {
        return new self(sprintf(
            'Replacement on [%s] from [%s] names missing target [%s].',
            $point,
            $owner,
            $targetId,
        ));
    }

    public static function replacementMissing(string $point, string $replacementId, string $owner): self
    {
        return new self(sprintf(
            'Replacement on [%s] from [%s] names missing replacement [%s].',
            $point,
            $owner,
            $replacementId,
        ));
    }

    public static function replacementForeignOwner(string $point, string $replacementId, string $owner, string $actualOwner): self
    {
        return new self(sprintf(
            'Replacement on [%s] from [%s] must own replacement [%s], which belongs to [%s].',
            $point,
            $owner,
            $replacementId,
            $actualOwner,
        ));
    }

    public static function replacementDuplicateIncoming(string $point, string $targetId, string $firstOwner, string $secondOwner): self
    {
        return new self(sprintf(
            'Replacement target [%s:%s] already has an incoming edge from [%s]; [%s] cannot add another.',
            $point,
            $targetId,
            $firstOwner,
            $secondOwner,
        ));
    }

    public static function replacementCycle(string $point, string $path): self
    {
        return new self(sprintf(
            'Replacement graph on [%s] is cyclic: %s.',
            $point,
            $path,
        ));
    }

    public static function menuAfterMissing(string $id, string $after, string $owner): self
    {
        return new self(sprintf(
            'Menu [%s] declared by [%s] names missing afterHandle [%s].',
            $id,
            $owner,
            $after,
        ));
    }

    public static function menuCycle(string $path): self
    {
        return new self(sprintf(
            'Menu afterHandle graph is cyclic: %s.',
            $path,
        ));
    }

    public static function corruptArtifact(string $reason): self
    {
        return new self(sprintf(
            'Compiled registry artifact is stale or corrupt (%s). Rerun modules:compile.',
            $reason,
        ));
    }

    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(code: ModuleErrorCode::CompilationException, category: ErrorCategory::Internal, retryable: false);
    }
}
