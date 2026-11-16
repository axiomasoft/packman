<?php

declare(strict_types=1);

namespace Packman\Modules\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Modules\Data\ModuleManifest;

/**
 * Base class of a Packman platform module or Composer-discovered plugin: a concrete
 * subclass describes itself declaratively via manifest(), discovered from `installed.json`
 * (`extra.packman.module` / configured key paths), optionally composing `With*` facets.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
abstract class ModuleDefinition
{
    abstract public function manifest(): ModuleManifest;
}
