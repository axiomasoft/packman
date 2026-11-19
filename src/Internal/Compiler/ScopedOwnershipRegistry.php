<?php

declare(strict_types=1);

namespace Packman\Schema\Internal\Compiler;

use Packman\Attributes\Internal;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Schema\Contracts\Compiler\SchemaOwnership;
use Packman\Schema\Contracts\Compiler\ScopedSchemaOwnership;

#[Internal]
final class ScopedOwnershipRegistry implements ScopedSchemaOwnership
{
    /** @var array<string, SchemaOwnershipRegistry> */
    private array $scopes = [];

    public function __construct(private readonly SchemaOwnershipRegistry $global = new SchemaOwnershipRegistry) {}

    public function forPrefix(string $prefix): SchemaOwnership
    {
        return $this->scopes[$prefix] ??= new SchemaOwnershipRegistry(fallback: $this->global);
    }

    public function declare(string $owner, array $nouns): void
    {
        $this->global->declare($owner, $nouns);
    }

    public function ownerOf(string $logical): ?string
    {
        $owner = $this->global->ownerOf($logical);
        foreach ($this->scopes as $scope) {
            $candidate = $scope->ownerOf($logical);

            if ($candidate === null) {
                continue;
            }

            if ($owner !== null && $owner !== $candidate) {
                throw new InvalidConfiguration(["packman.schema.nouns: table '{$logical}' has owners in multiple prefixes; select the extension's prefix"]);
            }
            $owner = $candidate;
        }

        return $owner;
    }

    public function owners(): array
    {
        $owners = $this->global->owners();
        foreach ($this->scopes as $scope) {
            foreach ($scope->owners() as $owner => $nouns) {
                $owners[$owner] = array_values(array_unique([...($owners[$owner] ?? []), ...$nouns]));
            }
        }

        return $owners;
    }
}
