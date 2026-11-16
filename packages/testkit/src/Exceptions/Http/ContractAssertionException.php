<?php

declare(strict_types=1);

namespace Packman\Testkit\Exceptions\Http;

use Packman\Attributes\Api;
use RuntimeException;

/** Thrown when a contract JSON Schema or OpenAPI specification check fails outside PHPUnit assertion context. */
#[Api(since: '1.0')]
final class ContractAssertionException extends RuntimeException {}
