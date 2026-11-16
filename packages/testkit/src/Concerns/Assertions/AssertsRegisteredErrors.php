<?php

declare(strict_types=1);

namespace Packman\Testkit\Concerns\Assertions;

use BackedEnum;
use Packman\Attributes\Api;
use Packman\Errors\Contracts\ErrorCode;
use Packman\Errors\Contracts\ProblemRegistry;
use Packman\Errors\Exceptions\CodedException;
use PHPUnit\Framework\Assert;

/** Assertions verifying that error enums and coded exceptions are wired cleanly in ProblemRegistry. */
#[Api(since: '1.0')]
trait AssertsRegisteredErrors
{
    /** @param class-string<ErrorCode&BackedEnum> $enumClass */
    protected function assertAllErrorCodesRegistered(ProblemRegistry $registry, string $enumClass): void
    {
        foreach ($enumClass::cases() as $case) {
            $definition = $registry->get($case);
            Assert::assertSame($case->value(), $definition->code->value());
            Assert::assertNotSame('', trim($definition->title));
            Assert::assertNotSame('', trim($definition->type));
        }
    }

    protected function assertCodedExceptionRegistered(ProblemRegistry $registry, CodedException $exception, ?int $expectedStatus = null): void
    {
        $registry->assertOccurrence($exception->errorDescriptor(), $expectedStatus);
    }
}
