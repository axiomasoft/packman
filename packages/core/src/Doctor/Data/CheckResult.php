<?php

declare(strict_types=1);

namespace Packman\Doctor\Data;

use Packman\Attributes\Api;
use Packman\Doctor\Enums\CheckStatus;

#[Api(since: '1.0')]
final readonly class CheckResult
{
    /**
     * @param  non-empty-list<DoctorFinding>  $findings
     * @param  string|null  $detail  exception message kept out of findings and JSON documents
     */
    public function __construct(
        public string $key,
        public string $title,
        public CheckStatus $status,
        public array $findings,
        public ?string $detail = null,
    ) {}
}
