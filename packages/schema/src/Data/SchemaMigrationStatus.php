<?php

declare(strict_types=1);

namespace Packman\Schema\Data;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(kind: SpiKind::Call, since: '1.0')]
final readonly class SchemaMigrationStatus
{
    /**
     * @param  list<string>  $applied
     * @param  list<string>  $pending
     * @param  list<string>  $unknown
     */
    public function __construct(public SchemaSet $set, public array $applied, public array $pending, public array $unknown = []) {}
}
