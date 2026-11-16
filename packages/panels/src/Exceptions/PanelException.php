<?php

declare(strict_types=1);

namespace Packman\Panels\Exceptions;

use Packman\Attributes\Api;
use Packman\Errors\Exceptions\CodedException;

/**
 * Base exception for all Packman Panels failures.
 */
#[Api(since: '1.0')]
abstract class PanelException extends CodedException {}
