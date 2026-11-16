<?php

declare(strict_types=1);

namespace Packman\Panels\SavedViews\Internal;

use LogicException;
use Packman\Attributes\Internal;
use Packman\Panels\SavedViews\Contracts\ViewSchemaProvider;
use Packman\Panels\SavedViews\Data\ViewContext;
use Packman\Panels\SavedViews\Data\ViewSchema;

#[Internal]
final class UnavailableViewSchemaProvider implements ViewSchemaProvider
{
    public function schema(ViewContext $context, string $entityHandle): ViewSchema
    {
        throw new LogicException('No saved-view schema provider is configured.');
    }
}
