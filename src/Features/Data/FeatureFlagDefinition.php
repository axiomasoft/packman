<?php

declare(strict_types=1);

namespace Packman\Features\Data;

use Packman\Attributes\Api;
use Packman\Data\Data\JsonDocument;
use Packman\Data\Laravel\JsonDocuments;

/**
 * Declarative feature-flag definition used by modules and plugins.
 */
#[Api(since: '1.0')]
final readonly class FeatureFlagDefinition
{
    public function __construct(
        public string $key,
        public bool $default = false,
        public ?string $module = null,
        public ?JsonDocument $payload = null,
    ) {}

    public static function make(string $key, bool $default = false): self
    {
        return new self(key: $key, default: $default);
    }

    public function module(string $module): self
    {
        return new self(
            key: $this->key,
            default: $this->default,
            module: $module,
            payload: JsonDocuments::nullable($this->payload),
        );
    }

    public function payload(JsonDocument $payload): self
    {
        return new self(
            key: $this->key,
            default: $this->default,
            module: $this->module,
            payload: JsonDocuments::nullable($payload),
        );
    }
}
