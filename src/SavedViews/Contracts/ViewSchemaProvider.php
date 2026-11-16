<?php

declare(strict_types=1);

namespace Packman\Panels\SavedViews\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Panels\SavedViews\Data\ViewContext;
use Packman\Panels\SavedViews\Data\ViewSchema;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ViewSchemaProvider
{
    public function schema(ViewContext $context, string $entityHandle): ViewSchema;
}
