<?php

declare(strict_types=1);

namespace Packman\Errors\Internal;

use Packman\Attributes\Internal;
use Packman\Errors\Contracts\ProblemDefinitionProvider;

/** Ownership is established by the Laravel composition boundary from Composer metadata. */
#[Internal]
final readonly class ProblemContribution
{
    /** @param list<string> $sourceRoots */
    public function __construct(
        public string $owner,
        public ProblemDefinitionProvider $provider,
        public ?string $package = null,
        public array $sourceRoots = [],
    ) {}
}
