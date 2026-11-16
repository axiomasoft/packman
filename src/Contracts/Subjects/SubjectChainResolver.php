<?php

declare(strict_types=1);

namespace Packman\Panels\Contracts\Subjects;

use Packman\Attributes\Api;
use Packman\Panels\Data\Subjects\SubjectChain;
use Packman\Panels\Data\Subjects\SubjectNode;
use Packman\Panels\Exceptions\Subjects\SubjectResolutionFailed;

#[Api(since: '1.0')]
interface SubjectChainResolver
{
    /**
     * @throws SubjectResolutionFailed
     */
    public function resolve(SubjectNode $leaf): SubjectChain;

    /**
     * @throws SubjectResolutionFailed
     */
    public function verify(SubjectChain $supplied): void;
}
