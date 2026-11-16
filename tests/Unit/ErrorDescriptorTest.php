<?php

declare(strict_types=1);

use Packman\Errors\Contracts\DescribesError;
use Packman\Errors\Contracts\ErrorCode;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Errors\Enums\UnavailabilityReason;

test('error category exposes the nine stable values', function (): void {
    expect(array_map(
        static fn (ErrorCategory $category): string => $category->value,
        ErrorCategory::cases(),
    ))->toBe([
        'authentication',
        'authorization',
        'validation',
        'conflict',
        'not_found',
        'rate_limit',
        'unsupported',
        'unavailable',
        'internal',
    ]);
});

test('unavailability reason exposes the five stable values', function (): void {
    expect(array_map(
        static fn (UnavailabilityReason $reason): string => $reason->value,
        UnavailabilityReason::cases(),
    ))->toBe([
        'capability_unavailable',
        'capability_disabled',
        'operation_unsupported',
        'access_denied',
        'feature_not_entitled',
    ]);
});

test('descriptor accepts a root nested and escaped pointer', function (string $path): void {
    $descriptor = new ErrorDescriptor(
        code: descriptorTestCode('packman.field_invalid'),
        category: ErrorCategory::Validation,
        path: $path,
    );

    expect($descriptor->path)->toBe($path)
        ->and($descriptor->retryable)->toBeFalse()
        ->and($descriptor->details)->toBe([]);
})->with([
    'root' => '',
    'empty token' => '/',
    'nested' => '/address/line',
    'escaped slash' => '/a~1b',
    'escaped tilde' => '/a~0b',
    'escaped nested' => '/m~0n/x~1y',
]);

test('descriptor accepts a null path', function (): void {
    $descriptor = new ErrorDescriptor(
        code: descriptorTestCode('packman.field_invalid'),
        category: ErrorCategory::Validation,
        path: null,
    );

    expect($descriptor->path)->toBeNull();
});

test('descriptor rejects an invalid code', function (string $code): void {
    expect(fn (): ErrorDescriptor => new ErrorDescriptor(
        code: descriptorTestCode($code),
        category: ErrorCategory::Internal,
    ))->toThrow(InvalidArgumentException::class, 'Error code is invalid.');
})->with([
    'empty' => '',
    'upper' => 'Field',
    'leading digit' => '1field',
    'double underscore' => 'field__name',
    'trailing underscore' => 'field_',
    'hyphen' => 'field-name',
    'overlong' => str_repeat('a', 121),
]);

test('descriptor accepts a code at the byte limit', function (): void {
    $code = 'packman.'.str_repeat('a', 112);

    expect(new ErrorDescriptor(code: descriptorTestCode($code), category: ErrorCategory::Internal)->code)->toBe($code);
});

test('descriptor rejects an invalid pointer escape or control character', function (string $path): void {
    expect(fn (): ErrorDescriptor => new ErrorDescriptor(
        code: descriptorTestCode('packman.field_invalid'),
        category: ErrorCategory::Validation,
        path: $path,
    ))->toThrow(InvalidArgumentException::class, 'Error path is not an RFC 6901 JSON Pointer.');
})->with([
    'missing slash' => 'address',
    'bad escape' => '/a~2',
    'dangling escape' => '/a~',
    'control' => "/a\nb",
]);

test('descriptor rejects a control character in detail', function (): void {
    expect(fn (): ErrorDescriptor => new ErrorDescriptor(
        code: descriptorTestCode('packman.field_invalid'),
        category: ErrorCategory::Validation,
        details: ['note' => "line\nbreak"],
    ))->toThrow(InvalidArgumentException::class, 'Error detail is not a bounded JSON value.');
});

test('descriptor rejects an oversize detail', function (): void {
    expect(fn (): ErrorDescriptor => new ErrorDescriptor(
        code: descriptorTestCode('packman.field_invalid'),
        category: ErrorCategory::Validation,
        details: ['note' => str_repeat('a', ErrorDescriptor::MAX_DETAIL_BYTES)],
    ))->toThrow(InvalidArgumentException::class, 'Error detail exceeds 4096 bytes.');
});

test('descriptor rejects a non json detail', function (array $details): void {
    expect(fn (): ErrorDescriptor => new ErrorDescriptor(
        code: descriptorTestCode('packman.field_invalid'),
        category: ErrorCategory::Validation,
        details: $details,
    ))->toThrow(InvalidArgumentException::class, 'Error detail is not a bounded JSON value.');
})->with([
    'nested list' => [['rows' => [['id']]]],
    'object' => [['cause' => new stdClass]],
    'non finite' => [['ratio' => INF]],
]);

test('describes error returns the same descriptor', function (): void {
    $descriptor = new ErrorDescriptor(
        code: descriptorTestCode('packman.field_invalid'),
        category: ErrorCategory::Validation,
    );
    $failure = new readonly class($descriptor) implements DescribesError
    {
        public function __construct(private ErrorDescriptor $descriptor) {}

        public function errorDescriptor(): ErrorDescriptor
        {
            return $this->descriptor;
        }
    };

    expect($failure->errorDescriptor())->toBe($descriptor);
});

test('descriptor detaches array-element references on construction', function (): void {
    $mutable = 'safe';
    $details = ['note' => &$mutable];
    $descriptor = new ErrorDescriptor(
        code: descriptorTestCode('packman.field_invalid'),
        category: ErrorCategory::Validation,
        details: $details,
    );
    $mutable = new stdClass;

    expect($descriptor->details)->toBe(['note' => 'safe']);
});

function descriptorTestCode(string $value): ErrorCode
{
    return new readonly class($value) implements ErrorCode
    {
        public function __construct(private string $code) {}

        public function value(): string
        {
            return $this->code;
        }
    };
}
