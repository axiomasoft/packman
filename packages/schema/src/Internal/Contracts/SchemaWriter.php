<?php

declare(strict_types=1);

namespace Packman\Schema\Internal\Contracts;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Packman\Schema\Internal\Data\SchemaObject;

/**
 * Where SpaceSchema sends a step's DDL: executed against the space connection.
 */
interface SchemaWriter
{
    public function connection(): Connection;

    /** @param  Closure(Blueprint): void  $define */
    public function create(string $table, Closure $define): void;

    /** @param  Closure(Blueprint): void  $define */
    public function alter(string $table, Closure $define): void;

    public function drop(string $table): void;

    public function createObject(SchemaObject $object): void;

    public function dropObject(SchemaObject $object): void;

    /** @param  Closure(Connection): void  $work */
    public function backfill(Closure $work): void;
}
