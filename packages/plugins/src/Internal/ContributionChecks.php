<?php

declare(strict_types=1);

namespace Packman\Plugins\Internal;

use Packman\Attributes\Spi;
use Packman\Plugins\Contracts\ExtensionPlugin;
use Packman\Plugins\Data\PluginManifest;
use ReflectionClass;

/**
 * Reusable all-at-once duplicate, class, and namespace ownership validator for plugin contributions.
 */
final class ContributionChecks
{
    /** @var array<string, array<string, string>> */
    private array $owners = [];

    /** @var array<string, string> */
    private array $origins = [];

    /** @var array<string, int> */
    private array $ordinals = [];

    /** @var array<string, array<string, string>> */
    private array $locations = [];

    /** @var list<string> */
    public array $problems;

    /** @param list<string> $problems */
    public function __construct(
        array &$problems,
        private readonly string $coreNamespacePrefix = 'Packman\\',
    ) {
        $this->problems = &$problems;
    }

    public function registerOwner(string $owner, string $class): void
    {
        $this->origins[$owner] = $class;
    }

    public function reserve(string $kind, string $contribution): void
    {
        $this->owners[$kind][$contribution] = 'core';
        $this->locations[$kind][$contribution] = 'core reserved '.$kind.' '.$contribution;
    }

    public function claim(string $kind, string $contribution, string $id, string $location = ''): bool
    {
        if ($location === '' && isset($this->origins[$id])) {
            $identity = $id.':'.$kind.':'.$contribution;
            $ordinal = $this->ordinals[$identity] = ($this->ordinals[$identity] ?? 0) + 1;
            $location = $this->origins[$id].'::'.$kind.'['.$contribution.']#'.$ordinal;
        }
        $existing = $this->owners[$kind][$contribution] ?? null;

        if ($existing !== null) {
            $this->problems[] = "PLUGIN_DUPLICATE_CONTRIBUTION {$kind} {$contribution} ({$existing}, {$id})".($location === '' ? '' : " declarations [{$this->locations[$kind][$contribution]}, {$location}]");

            return false;
        }
        $this->owners[$kind][$contribution] = $id;
        $this->locations[$kind][$contribution] = $location;

        return true;
    }

    public function instantiable(string $class): bool
    {
        if (! class_exists($class)) {
            return false;
        }
        $reflection = new ReflectionClass($class);

        return $reflection->isInstantiable() && ($reflection->getConstructor()?->getNumberOfRequiredParameters() ?? 0) === 0;
    }

    /** @param array{class: string, manifest: PluginManifest} $node */
    public function checkClass(string $id, array $node, string $kind, mixed $class, string $parent): void
    {
        if (! is_string($class) || ! is_a($class, $parent, true)) {
            $name = is_string($class) ? $class : get_debug_type($class);
            $this->problems[] = "PLUGIN_INVALID_CONTRIBUTION {$id} {$kind} {$name} is not a {$parent}";

            return;
        }
        $this->checkNamespace($id, $node, $kind, $class);
    }

    /**
     * A contributed class lives under the plugin's namespace root (its first two segments) or is a platform #[Spi] class.
     *
     * @param  array{class: string, manifest: PluginManifest}  $node
     */
    public function checkNamespace(string $id, array $node, string $kind, string $class): void
    {
        $segments = explode('\\', ltrim($node['class'], '\\'));
        $root = implode('\\', array_slice($segments, 0, max(1, min(2, count($segments) - 1)))).'\\';
        $class = ltrim($class, '\\');

        if (str_starts_with($class, $root)) {
            return;
        }

        if ((class_exists($class) || interface_exists($class)) && $this->isCoreSpi(new ReflectionClass($class))) {
            return;
        }

        $this->problems[] = "PLUGIN_FOREIGN_NAMESPACE {$id} {$kind} {$class} is outside {$root}";
    }

    /** @param ReflectionClass<object> $class */
    private function isCoreSpi(ReflectionClass $class): bool
    {
        $core = dirname((string) new ReflectionClass(ExtensionPlugin::class)->getFileName(), 3).DIRECTORY_SEPARATOR;

        return str_starts_with($class->getName(), $this->coreNamespacePrefix)
            && str_starts_with((string) $class->getFileName(), $core)
            && $class->getAttributes(Spi::class) !== [];
    }
}
