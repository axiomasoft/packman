<?php

declare(strict_types=1);

namespace Fixture\StandalonePhp;

use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Errors\Exceptions\CodedException;

final class ConsumerPayloadException extends CodedException
{
    public function __construct(private readonly string $field)
    {
        parent::__construct('Invalid payload field.');
    }

    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: ConsumerErrorCode::InvalidPayload,
            category: ErrorCategory::Validation,
            path: '/'.$this->field,
            details: ['field' => $this->field],
        );
    }
}
