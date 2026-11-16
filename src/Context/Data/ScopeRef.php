<?php

declare(strict_types=1);

namespace Packman\Context\Data;

use InvalidArgumentException;
use Packman\Attributes\Api;
use Packman\Context\Contracts\ContextScopeInterface;

#[Api(since: '1.0')]
final readonly class ScopeRef implements ContextScopeInterface
{
    /** @var array<string, scalar|null> */
    public array $attributes;

    /**
     * @param  array<array-key, mixed>  $attributes
     */
    public function __construct(
        public string $id,
        array $attributes = [],
    ) {
        if ($this->id === '') {
            throw new InvalidArgumentException('Scope identifier must be a non-empty string.');
        }

        $normalized = [];
        foreach ($attributes as $key => $value) {
            if (! is_string($key) || $key === '' || (is_float($value) && ! is_finite($value)) || (! is_scalar($value) && $value !== null)) {
                throw new InvalidArgumentException('ScopeRef attributes must have non-empty string keys and finite scalar or null values.');
            }
            $normalized[$key] = $value;
        }

        $this->attributes = $normalized;
    }

    public function id(): string
    {
        return $this->id;
    }
}
