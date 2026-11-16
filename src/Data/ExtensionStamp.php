<?php

declare(strict_types=1);

namespace Packman\Modules\Data;

use InvalidArgumentException;
use Packman\Attributes\Api;

/**
 * Point, extension id, extension version and diagnostic registry fingerprint.
 *
 * Dependency-neutral: no Laravel types. Tenant activation is not part of the stamp.
 */
#[Api(since: '1.0')]
final readonly class ExtensionStamp
{
    public function __construct(
        public string $point,
        public string $id,
        public string $version,
        public string $registryFingerprint,
    ) {
        self::assertScalar(field: 'point', value: $point);
        self::assertScalar(field: 'id', value: $id);
        self::assertScalar(field: 'version', value: $version);
        self::assertScalar(field: 'registryFingerprint', value: $registryFingerprint);
    }

    /** @return array{point: string, id: string, version: string, registryFingerprint: string} */
    public function toArray(): array
    {
        return [
            'point' => $this->point,
            'id' => $this->id,
            'version' => $this->version,
            'registryFingerprint' => $this->registryFingerprint,
        ];
    }

    /** @param array{point: string, id: string, version: string, registryFingerprint: string} $value */
    public static function fromArray(array $value): self
    {
        return new self(
            point: self::scalar(value: $value, field: 'point'),
            id: self::scalar(value: $value, field: 'id'),
            version: self::scalar(value: $value, field: 'version'),
            registryFingerprint: self::scalar(value: $value, field: 'registryFingerprint'),
        );
    }

    /** @param array<string, mixed> $value */
    private static function scalar(array $value, string $field): string
    {
        $scalar = $value[$field] ?? null;

        if (! is_string($scalar)) {
            throw new InvalidArgumentException(sprintf(
                'Extension stamp [%s] must be a non-empty string.',
                $field,
            ));
        }

        self::assertScalar(field: $field, value: $scalar);

        return $scalar;
    }

    private static function assertScalar(string $field, string $value): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException(sprintf(
                'Extension stamp [%s] must be a non-empty string.',
                $field,
            ));
        }
    }
}
