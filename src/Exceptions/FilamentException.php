<?php

declare(strict_types=1);

namespace Packman\Filament\Exceptions;

use Packman\Attributes\Api;
use Packman\Errors\Exceptions\CodedException;

#[Api(since: '1.0')]
abstract class FilamentException extends CodedException {}
