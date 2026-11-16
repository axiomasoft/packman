<?php

declare(strict_types=1);

namespace Packman\Data\Data;

use Packman\Attributes\Api;

/** Immutable JSON value. Laravel adapters decode it only at storage/transport boundaries. */
#[Api(since: '1.0')]
final readonly class JsonDocument
{
    public function __construct(public string $canonicalJson)
    {
        json_decode($canonicalJson, true, 512, JSON_THROW_ON_ERROR);
    }
}
