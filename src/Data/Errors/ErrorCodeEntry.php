<?php

declare(strict_types=1);

namespace Packman\DevTools\Data\Errors;

use Packman\Attributes\Api;

/** Normalized record for one declared ErrorCode enum case discovered by AST/source scanning. */
#[Api(since: '1.0')]
final readonly class ErrorCodeEntry
{
    public function __construct(
        public string $package,
        public string $enumClass,
        public string $caseName,
        public string $wireCode,
        public string $file,
        public int $line,
        public ?string $problemType = null,
        public ?int $defaultStatus = null,
        public ?string $title = null,
        public ?bool $retryable = null,
        public ?string $category = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'package' => $this->package,
            'enum' => $this->enumClass,
            'case' => $this->caseName,
            'code' => $this->wireCode,
            'type' => $this->problemType,
            'status' => $this->defaultStatus,
            'title' => $this->title,
            'retryable' => $this->retryable,
            'category' => $this->category,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
