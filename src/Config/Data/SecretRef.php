<?php

declare(strict_types=1);

namespace Packman\Config\Data;

use LogicException;
use Packman\Attributes\Api;
use SensitiveParameter;
use Stringable;

#[Api(since: '1.0')]
final readonly class SecretRef implements Stringable
{
    public function __construct(
        #[SensitiveParameter]
        private string $value,
    ) {}

    public function reveal(): string
    {
        return $this->value;
    }

    public function isPresent(): bool
    {
        return $this->value !== '';
    }

    public function __toString(): string
    {
        return '[redacted]';
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['value' => '[redacted]'];
    }

    /** @return array<string, string> */
    public function __serialize(): array
    {
        throw new LogicException('SecretRef must not be serialized.');
    }
}
