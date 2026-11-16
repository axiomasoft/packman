<?php

declare(strict_types=1);

namespace Packman\Schema\Internal\Compiler;

use Illuminate\Support\Str;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Schema\Contracts\Compiler\SchemaOwnership;

final class SchemaOwnershipRegistry implements SchemaOwnership
{
    /** @var array<string, string> noun => owner */
    private array $nouns = [];

    /** @var array<string, string|null> logical table => owner, memoized */
    private array $resolved = [];

    public function __construct(private readonly string $configPath = 'packman.schema.nouns') {}

    public function declare(string $owner, array $nouns): void
    {
        foreach ($nouns as $noun) {
            if (preg_match('/\A[a-z][a-z0-9]*(_[a-z0-9]+)*\z/', $noun) !== 1) {
                throw new InvalidConfiguration(["{$this->configPath}.{$owner}: '{$noun}' is not a lower-case snake noun"]);
            }

            $current = $this->nouns[$noun] ?? null;

            if ($current !== null && $current !== $owner) {
                throw new InvalidConfiguration(["{$this->configPath}.{$owner}: noun '{$noun}' is already owned by '{$current}'"]);
            }

            $this->nouns[$noun] = $owner;
        }

        $this->resolved = [];
    }

    public function ownerOf(string $logical): ?string
    {
        if (array_key_exists($logical, $this->resolved)) {
            return $this->resolved[$logical];
        }

        $owner = null;
        $longest = 0;

        foreach ($this->nouns as $noun => $candidate) {
            $covers = $logical === $noun
                || $logical === Str::plural($noun)
                || str_starts_with($logical, "{$noun}_");

            // The longest noun wins, so `x_acme` beats a hypothetical `x`.
            if ($covers && strlen($noun) > $longest) {
                $owner = $candidate;
                $longest = strlen($noun);
            }
        }

        return $this->resolved[$logical] = $owner;
    }

    public function owners(): array
    {
        $owners = [];

        foreach ($this->nouns as $noun => $owner) {
            $owners[$owner][] = $noun;
        }

        return $owners;
    }
}
