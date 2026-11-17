<?php

declare(strict_types=1);

namespace Packman\Config\Laravel;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Config\Exceptions\InvalidConfiguration;

#[Spi(kind: SpiKind::Call, since: '1.0')]
final class ConfigurationValidator
{
    /** @var list<string> */
    private array $items = [];

    public function add(string $path, string $message): void
    {
        $this->items[] = $path.': '.$message;
    }

    /**
     * Rejects unknown keys in a config map with Levenshtein typo suggestions.
     *
     * @param  array<array-key, mixed>  $config
     * @param  list<string>  $allowedKeys
     */
    public function rejectUnknownKeys(array $config, array $allowedKeys, string $path): void
    {
        $allowed = array_flip($allowedKeys);

        foreach (array_keys($config) as $key) {
            if (! is_string($key)) {
                $this->add($path, 'configuration keys must be strings');

                continue;
            }

            if (isset($allowed[$key])) {
                continue;
            }

            $this->add("{$path}.{$key}", 'unknown key'.self::typoSuggestion($key, $allowedKeys));
        }
    }

    public function failIfAny(): void
    {
        if ($this->items === []) {
            return;
        }

        throw new InvalidConfiguration($this->items);
    }

    /** @param list<string> $candidates */
    private static function typoSuggestion(string $unknown, array $candidates): string
    {
        $best = null;
        $bestDistance = 4;

        foreach ($candidates as $candidate) {
            $distance = levenshtein($unknown, $candidate);

            if ($distance < $bestDistance) {
                [$best, $bestDistance] = [$candidate, $distance];
            }
        }

        return $best === null ? '' : " (did you mean \"{$best}\"?)";
    }
}
