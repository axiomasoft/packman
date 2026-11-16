<?php

declare(strict_types=1);

use Packman\Errors\Contracts\ProblemRegistry;
use Packman\Errors\Data\ProblemDefinition;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Modules\Enums\ModuleErrorCode;
use Packman\Modules\Exceptions\Compiler\CompilationException;
use Packman\Modules\Exceptions\Registry\ModuleNotFound;
use Packman\Modules\Exceptions\Registry\RegistryInputUnavailable;
use Packman\Testkit\Concerns\Assertions\AssertsRegisteredErrors;

uses(AssertsRegisteredErrors::class);

it('registers all ModuleErrorCode cases in the runtime ProblemRegistry', function (): void {
    $registry = resolve(ProblemRegistry::class);

    $this->assertAllErrorCodesRegistered($registry, ModuleErrorCode::class);

    $compilation = $registry->fromWire('modules.compilation_failed');

    expect($compilation)->toBeInstanceOf(ProblemDefinition::class)
        ->and($compilation->code)->toBe(ModuleErrorCode::CompilationException)
        ->and($compilation->type)->toBe('urn:packman:problem:modules.compilation_failed')
        ->and($compilation->defaultStatus)->toBe(500)
        ->and($compilation->category)->toBe(ErrorCategory::Internal)
        ->and($registry->owner(ModuleErrorCode::CompilationException))->toBe('axiomasoft/packman-modules');

    $this->assertCodedExceptionRegistered($registry, new ModuleNotFound('acme/missing'), 404);
    $this->assertCodedExceptionRegistered($registry, RegistryInputUnavailable::missing('/tmp/modules.php'), 503);
    $this->assertCodedExceptionRegistered($registry, CompilationException::unmetRequirement('acme/app', 'acme/missing'), 500);
});
