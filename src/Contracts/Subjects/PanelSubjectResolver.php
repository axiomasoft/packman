<?php

declare(strict_types=1);

namespace Packman\Panels\Contracts\Subjects;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Panels\Data\Subjects\SubjectChain;
use Packman\Panels\Data\Subjects\SubjectNode;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface PanelSubjectResolver
{
    /** Root-to-parent ancestors; null means the subject does not exist in this tenant. */
    public function resolve(SubjectNode $leaf): ?SubjectChain;
}
