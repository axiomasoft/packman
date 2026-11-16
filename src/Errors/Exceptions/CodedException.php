<?php

declare(strict_types=1);

namespace Packman\Errors\Exceptions;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Errors\Contracts\DescribesError;
use Packman\Errors\Data\ErrorDescriptor;
use RuntimeException;

/** Implementations describe failures with safe data, independently of their diagnostic message. */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
abstract class CodedException extends RuntimeException implements DescribesError
{
    abstract public function errorDescriptor(): ErrorDescriptor;
}
