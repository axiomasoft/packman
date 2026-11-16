<?php

declare(strict_types=1);

namespace Packman\Panels\Contracts\Subjects;

use Packman\Attributes\Api;
use Packman\Panels\Builder\Panel;
use Packman\Panels\Data\Panel\PanelFormData;
use Packman\Panels\Data\Subjects\SubjectNode;
use Packman\Panels\Exceptions\Definition\UnknownSlotException;
use Packman\Panels\Exceptions\Permissions\PolicyConflictException;

#[Api(since: '1.0')]
interface PanelFormProjector
{
    /**
     * @throws UnknownSlotException
     * @throws PolicyConflictException
     */
    public function project(Panel $panel, SubjectNode $subject, string $slotKey): PanelFormData;
}
