<?php

declare(strict_types=1);

namespace Packman\Plugins\Exceptions\Graph;

use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Errors\Exceptions\CodedException;
use Packman\Plugins\Enums\PluginErrorCode;

#[Api(since: '1.0')]
final class InvalidExtensionGraphException extends CodedException
{
    /** @var list<string> */
    private array $problems = [];

    /** @param non-empty-list<string> $problems */
    public static function fromProblems(array $problems): self
    {
        $problems = array_values(array_unique($problems));
        sort($problems, SORT_STRING);
        $count = count($problems);
        $exception = new self(
            'Invalid extension graph ('.$count.' problem'.($count === 1 ? '' : 's')."):\n - ".implode("\n - ", $problems),
        );
        $exception->problems = $problems;

        return $exception;
    }

    /** @return list<string> */
    public function problems(): array
    {
        return $this->problems;
    }

    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(code: PluginErrorCode::InvalidGraph, category: ErrorCategory::Internal, retryable: false);
    }
}
