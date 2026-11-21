<?php

declare(strict_types=1);

namespace Packman\Config\Data;

use InvalidArgumentException;
use Packman\Attributes\Api;
use Packman\Config\Exceptions\InvalidConfiguration;
use Stringable;

/**
 * Validated SQL table prefix matching `/\A[a-z][a-z0-9]{1,6}_\z/`.
 */
#[Api(since: '1.0')]
final readonly class TablePrefix implements Stringable
{
    public const string PATTERN = '/\A[a-z][a-z0-9]{1,6}_\z/';

    public function __construct(public string $prefix)
    {
        if (preg_match(self::PATTERN, $this->prefix) !== 1) {
            throw new InvalidConfiguration([
                'table_prefix: expected 2..7 lowercase letters or digits starting with a letter and ending with "_"',
            ]);
        }
    }

    public static function fromArrayValue(mixed $value, string $path = 'table_prefix'): self
    {
        if (! is_string($value) || preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidConfiguration([
                "{$path}: expected 2..7 lowercase letters or digits starting with a letter and ending with \"_\"",
            ]);
        }

        return new self($value);
    }

    public function table(string $logicalName): string
    {
        if (preg_match('/\A[a-z][a-z0-9]*(_[a-z0-9]+)*\z/', $logicalName) !== 1) {
            throw new InvalidArgumentException("Logical table '{$logicalName}' must be a lower-case snake name.");
        }

        return $this->prefix.$logicalName;
    }

    public function value(): string
    {
        return $this->prefix;
    }

    public function __toString(): string
    {
        return $this->prefix;
    }
}
