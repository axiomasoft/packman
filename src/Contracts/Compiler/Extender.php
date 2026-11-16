<?php

declare(strict_types=1);

namespace Packman\Modules\Contracts\Compiler;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Marker interface for the closed set of 8 extenders. A third-party class
 * implementing this outside the set is rejected by RegistryCompiler with a
 * reference to the RFC process.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface Extender {}
