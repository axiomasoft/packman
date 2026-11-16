<?php

declare(strict_types=1);

namespace Packman\Filament\Internal\Configuration;

use Packman\Attributes\Internal;
use Packman\Filament\Exceptions\Binding\InvalidBindingStateException;

/**
 * Validated package configuration options for `packman-filament`.
 */
#[Internal]
final readonly class FilamentOptions
{
    /** @var list<string> */
    private const array DEFAULT_ABILITIES = [
        'view_any',
        'view',
        'create',
        'update',
        'delete',
        'delete_any',
        'restore',
        'restore_any',
        'force_delete',
        'force_delete_any',
        'replicate',
        'reorder',
    ];

    /**
     * @param  array<string, bool>  $resources
     * @param  list<string>  $abilities
     * @param  array{resources: list<string>, pages: list<string>, widgets: list<string>}  $exclude
     * @param  list<string>  $persistentMiddleware
     */
    public function __construct(
        public string $navigationGroup,
        public ?string $guardPanel,
        public bool $enforce,
        public array $resources,
        public array $abilities,
        public array $exclude,
        public array $persistentMiddleware,
        public bool $refuseUnverifiableDownloads,
    ) {}

    /**
     * @param  array<string, mixed>  $values
     */
    public static function fromArray(array $values): self
    {
        $navigationGroup = $values['navigation_group'] ?? 'Packman';

        if (! is_string($navigationGroup) || trim($navigationGroup) === '') {
            throw InvalidBindingStateException::invalidConfiguration('navigation_group must be a non-empty string.');
        }

        $guardPanel = self::optionalString($values['guard_panel'] ?? null, 'guard_panel');
        $enforce = (bool) ($values['enforce'] ?? false);

        $rawResources = $values['resources'] ?? [];

        if (! is_array($rawResources)) {
            throw InvalidBindingStateException::invalidConfiguration('resources must be an associative array of booleans.');
        }

        $resources = [];

        foreach ($rawResources as $key => $enabled) {
            if (! is_string($key) || trim($key) === '') {
                throw InvalidBindingStateException::invalidConfiguration('resources keys must be non-empty strings.');
            }

            $resources[$key] = is_bool($enabled) ? $enabled : true;
        }

        $abilities = self::abilityNames($values['abilities'] ?? self::DEFAULT_ABILITIES);

        $rawExclude = (array) ($values['exclude'] ?? []);
        $exclude = [
            'resources' => self::strings($rawExclude['resources'] ?? [], 'exclude.resources'),
            'pages' => self::strings($rawExclude['pages'] ?? [], 'exclude.pages'),
            'widgets' => self::strings($rawExclude['widgets'] ?? [], 'exclude.widgets'),
        ];

        $persistentMiddleware = self::strings($values['persistent_middleware'] ?? [], 'persistent_middleware');
        $refuseUnverifiableDownloads = (bool) ($values['refuse_unverifiable_downloads'] ?? true);

        return new self(
            navigationGroup: trim($navigationGroup),
            guardPanel: $guardPanel,
            enforce: $enforce,
            resources: $resources,
            abilities: $abilities,
            exclude: $exclude,
            persistentMiddleware: $persistentMiddleware,
            refuseUnverifiableDownloads: $refuseUnverifiableDownloads,
        );
    }

    public function resourceEnabled(string $key, bool $default = true): bool
    {
        return $this->resources[$key] ?? $default;
    }

    /**
     * @return list<string>
     */
    private static function abilityNames(mixed $abilities): array
    {
        $names = self::strings($abilities, 'abilities');

        foreach ($names as $name) {
            if (preg_match('/\A[a-z][a-z0-9]*(?:_[a-z0-9]+)*\z/', $name) !== 1) {
                throw InvalidBindingStateException::invalidConfiguration('The ability "'.$name.'" is not snake_case.');
            }
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $values, string $name): array
    {
        if (! is_array($values)) {
            throw InvalidBindingStateException::invalidConfiguration($name.' must be a list of strings.');
        }

        $strings = [];

        foreach ($values as $value) {
            if (! is_string($value) || $value === '') {
                throw InvalidBindingStateException::invalidConfiguration($name.' must be a list of non-empty strings.');
            }

            $strings[] = $value;
        }

        return array_values(array_unique($strings));
    }

    private static function optionalString(mixed $value, string $name): ?string
    {
        return match (true) {
            $value === null => null,
            is_string($value) && $value !== '' => $value,
            default => throw InvalidBindingStateException::invalidConfiguration($name.' must be a non-empty string or null.'),
        };
    }
}
