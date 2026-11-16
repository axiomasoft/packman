<?php

declare(strict_types=1);

namespace Packman\Errors\Data;

use InvalidArgumentException;
use Packman\Attributes\Api;
use Packman\Errors\Contracts\ErrorCode;
use Packman\Errors\Enums\ErrorCategory;

/**
 * Stable machine shape of a package-owned failure. Human message, exception
 * class and HTTP status are not fields of this contract.
 */
#[Api(since: '1.0')]
final readonly class ErrorDescriptor
{
    public string $code;

    public ErrorCode $errorCode;

    /** @var array<string, scalar|list<scalar|null>|null> */
    public array $details;

    public const int MAX_CODE_BYTES = 120;

    public const int MAX_DETAIL_BYTES = 4096;

    /**
     * @param  array<string, scalar|list<scalar|null>|null>  $details
     */
    public function __construct(
        ErrorCode $code,
        public ErrorCategory $category,
        public bool $retryable = false,
        public ?string $path = null,
        array $details = [],
    ) {
        $this->errorCode = $code;
        $this->code = $code->value();

        if (preg_match('/\A[a-z][a-z0-9]*(?:-[a-z0-9]+)*\.[a-z][a-z0-9]*(?:_[a-z0-9]+)*\z/D', $this->code) !== 1 || strlen($this->code) > self::MAX_CODE_BYTES) {
            throw new InvalidArgumentException('Error code is invalid.');
        }

        $this->assertPath($path);
        $this->details = $this->normalizeDetails($details);
    }

    private function assertPath(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        if (! str_starts_with($path, '/') || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            throw new InvalidArgumentException('Error path is not an RFC 6901 JSON Pointer.');
        }

        foreach (explode('/', substr($path, 1)) as $token) {
            if (preg_match('/~(?![01])/', $token) === 1) {
                throw new InvalidArgumentException('Error path is not an RFC 6901 JSON Pointer.');
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $details
     * @return array<string, scalar|list<scalar|null>|null>
     */
    private function normalizeDetails(array $details): array
    {
        $normalized = [];

        foreach ($details as $key => $value) {
            if (! is_string($key) || $key === '' || $this->hasControlCharacter($key)) {
                throw new InvalidArgumentException('Error detail is not a bounded JSON value.');
            }

            $normalized[$key] = $this->normalizeDetailValue($value);
        }

        $encoded = json_encode($normalized, JSON_THROW_ON_ERROR);

        if (strlen($encoded) > self::MAX_DETAIL_BYTES) {
            throw new InvalidArgumentException('Error detail exceeds 4096 bytes.');
        }

        return $normalized;
    }

    /** @return scalar|list<scalar|null>|null */
    private function normalizeDetailValue(mixed $value): string|int|float|bool|array|null
    {
        if ($value === null || is_bool($value) || is_int($value)) {
            return $value;
        }

        if (is_string($value)) {
            if ($this->hasControlCharacter($value)) {
                throw new InvalidArgumentException('Error detail is not a bounded JSON value.');
            }

            return $value;
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException('Error detail is not a bounded JSON value.');
            }

            return $value;
        }

        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException('Error detail is not a bounded JSON value.');
        }

        $list = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                throw new InvalidArgumentException('Error detail is not a bounded JSON value.');
            }

            /** @var scalar|null $scalarItem */
            $scalarItem = $this->normalizeDetailValue($item);
            $list[] = $scalarItem;
        }

        return $list;
    }

    private function hasControlCharacter(string $value): bool
    {
        return preg_match('/[\x00-\x1F\x7F]/', $value) === 1;
    }
}
