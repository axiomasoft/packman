<?php

declare(strict_types=1);

namespace Fixture\StandalonePhp;

use Packman\Errors\Contracts\ErrorCode;

enum ConsumerErrorCode: string implements ErrorCode
{
    case InvalidPayload = 'consumer.invalid_payload';

    public function value(): string
    {
        return $this->value;
    }
}
