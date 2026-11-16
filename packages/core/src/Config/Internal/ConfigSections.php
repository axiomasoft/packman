<?php

declare(strict_types=1);

namespace Packman\Config\Internal;

use Closure;
use Packman\Attributes\Internal;
use Packman\Config\Exceptions\InvalidConfiguration;

/**
 * Registry of owned root configuration sections that seals on first read.
 */
#[Internal]
final class ConfigSections
{
    /** @var array<string, Closure(array<string, mixed>): void> */
    private array $owners = [];

    private bool $frozen = false;

    /** @param Closure(array<string, mixed>): void $validate */
    public function own(string $root, Closure $validate): void
    {
        if ($this->frozen) {
            throw InvalidConfiguration::forMessage("The section {$root} is registered after the configuration was read: register it in the register() method of a service provider.");
        }

        if (preg_match('/\A[a-z][a-z0-9_]*\z/', $root) !== 1) {
            throw InvalidConfiguration::forMessage("A configuration section is a lowercase root key, got \"{$root}\".");
        }

        if (isset($this->owners[$root])) {
            throw InvalidConfiguration::forMessage("The section {$root} already has an owner.");
        }

        $this->owners[$root] = $validate;
    }

    /** @return array<string, Closure(array<string, mixed>): void> */
    public function owned(): array
    {
        $this->frozen = true;

        return $this->owners;
    }

    /** @param array<string, mixed> $config */
    public function assertKnownKeys(array $config): void
    {
        $owned = $this->owned();

        foreach ($config as $root => $section) {
            if (! isset($owned[$root])) {
                throw InvalidConfiguration::forMessage("{$root}: unknown configuration section.");
            }

            if (! is_array($section)) {
                throw InvalidConfiguration::forMessage("{$root}: section must be an array.");
            }

            /** @var array<string, mixed> $sectionMap */
            $sectionMap = $section;
            $owned[$root]($sectionMap);
        }
    }
}
