<?php

declare(strict_types=1);

namespace Packman\Config\Internal;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Arr;
use Packman\Attributes\Internal;
use Packman\Config\Exceptions\InvalidConfiguration;

#[Internal]
final class ConfigNormalizer
{
    /** @var array<string, string> */
    private array $defaultPaths = [];

    /** @var array<string, array<string, mixed>> */
    private array $defaults = [];

    /**
     * Keys whose configured array replaces the package defaults instead of recursive dot-merging.
     *
     * @param  list<string>  $replacementMaps
     */
    public function __construct(private array $replacementMaps = []) {}

    public function registerReplacementMaps(string ...$maps): void
    {
        foreach ($maps as $map) {
            if (! in_array($map, $this->replacementMaps, true)) {
                $this->replacementMaps[] = $map;
            }
        }
    }

    public function registerDefaults(string $root, string $path): void
    {
        if (($this->defaultPaths[$root] ?? null) !== $path) {
            unset($this->defaults[$root]);
        }
        $this->defaultPaths[$root] = $path;
    }

    /** @return array<string, array<string, mixed>> */
    public function packageDefaults(): array
    {
        $defaults = [];
        foreach ($this->defaultPaths as $root => $path) {
            $this->defaults[$root] ??= $this->loadDefaults($path);
            $defaults[$root] = $this->defaults[$root];
        }

        return $defaults;
    }

    /** @param array<string, array<string, mixed>> $packageDefaults */
    public function normalize(Repository $config, array $packageDefaults): void
    {
        $defaults = $this->stringKeyedEntries(Arr::dot($packageDefaults));
        $configuredMaps = array_filter($this->replacementMaps, $config->has(...));

        foreach ($defaults as $key => $value) {
            $insideReplacementMap = array_any($configuredMaps, fn (string $map): bool => str_starts_with($key, $map.'.'));

            if (! $insideReplacementMap && ! $config->has($key)) {
                $config->set($key, $value);
            }
        }
    }

    /** @return array<string, mixed> */
    private function loadDefaults(string $path): array
    {
        if (! is_file($path)) {
            throw InvalidConfiguration::forMessage('Package config file "'.$path.'" does not exist.');
        }

        $values = require $path;

        if (! is_array($values)) {
            throw InvalidConfiguration::forMessage('Package config file "'.$path.'" must return an array.');
        }
        $defaults = [];
        foreach ($values as $key => $value) {
            if (! is_string($key)) {
                throw InvalidConfiguration::forMessage('Package config file "'.$path.'" must use string root keys.');
            }
            $defaults[$key] = $value;
        }

        return $defaults;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<string, mixed>
     */
    private function stringKeyedEntries(array $values): array
    {
        $entries = [];
        foreach ($values as $key => $value) {
            if (is_string($key)) {
                $entries[$key] = $value;
            }
        }

        return $entries;
    }
}
