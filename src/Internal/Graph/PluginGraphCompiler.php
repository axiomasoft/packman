<?php

declare(strict_types=1);

namespace Packman\Plugins\Internal\Graph;

use Illuminate\Contracts\Container\Container;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Extensions\Data\ExtensionFacetProfile;
use Packman\Extensions\ExtensionInspector;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Contracts\Registry\PluginActivationGate;
use Packman\Plugins\Data\Capabilities\CapabilityDefinition;
use Packman\Plugins\Data\Capabilities\CapabilityProvider;
use Packman\Plugins\Data\Contributions\ContributionDescription;
use Packman\Plugins\Data\Contributions\ContributionKind;
use Packman\Plugins\Data\Contributions\SlotContribution;
use Packman\Plugins\Data\Contributions\SlotDefinition;
use Packman\Plugins\Data\Plugin\PluginDescription;
use Packman\Plugins\Enums\Contributions\Cardinality;
use Packman\Plugins\Enums\Contributions\SlotKind;
use Packman\Plugins\Internal\Contributions\FrozenContributions;
use Packman\Plugins\Laravel\PluginRegistrar;
use Throwable;

/**
 * Compiles plugin declarations (both imperative PluginRegistrar calls and declarative `With*` facets)
 * into a FrozenContributions graph using Kahn's topological sort and transitive ancestor ownership enforcement.
 */
final readonly class PluginGraphCompiler
{
    private const string KEY_PATTERN = '/\A[a-z][a-z0-9]*([._-][a-z0-9]+)*\z/';

    private const string CONTRIBUTION_ID_PATTERN = '/\A[a-z0-9][a-z0-9._-]*\z/';

    public function __construct(
        private Container $container,
        private string $corePluginId = '',
        private ?string $database = null,
        private ?PluginActivationGate $activationGate = null,
    ) {}

    /**
     * @param  list<PluginEntry>  $entries
     * @param  list<string>  $disabled
     *
     * @throws InvalidConfiguration
     */
    public function compile(array $entries, array $disabled = []): FrozenContributions
    {
        $allNodes = $this->nodes($entries);
        [$activeNodes, $disabledIds] = $this->partitionActiveNodes($allNodes, $disabled);
        $dependencies = $this->dependencies($activeNodes, $allNodes);
        $order = $this->order($activeNodes, $dependencies);
        $ancestors = $this->ancestors($order, $this->dependencies($activeNodes, $allNodes, includeOptional: false));

        [$kinds, $items, $contributionsByPlugin] = $this->compileKindsAndItems($activeNodes, $order, $ancestors);
        [$capabilities, $providers] = $this->compileCapabilities($activeNodes, $order, $ancestors);
        [$slots, $slotContributions] = $this->compileSlots($activeNodes, $order, $ancestors);

        $descriptions = [];
        $facetsByPlugin = [];

        foreach ($order as $id) {
            $node = $activeNodes[$id];
            $facetsByPlugin[$id] = $node->facets;
            $descriptions[] = new PluginDescription(
                $id,
                $node->manifest->version,
                $node->entry->source,
                $node->plugin::class,
                $node->manifest->requires,
                $node->manifest->optional,
                $node->declarations->kinds(),
                $contributionsByPlugin[$id] ?? [],
                $node->facets,
            );
        }

        return new FrozenContributions(
            $kinds,
            $items,
            $descriptions,
            $capabilities,
            $providers,
            $slots,
            $slotContributions,
            $disabledIds,
            $facetsByPlugin,
        );
    }

    /**
     * @param  array<string, PluginNode>  $allNodes
     * @param  list<string>  $disabled
     * @return array{0: array<string, PluginNode>, 1: list<string>}
     */
    private function partitionActiveNodes(array $allNodes, array $disabled): array
    {
        $disabledSet = [];

        foreach ($disabled as $id) {
            if (! isset($allNodes[$id])) {
                throw new InvalidConfiguration(["packman.disabled_plugins: PLUGIN_UNKNOWN_DISABLED {$id}"]);
            }

            $disabledSet[$id] = true;
        }

        $activeNodes = [];
        $disabledIds = [];

        foreach ($allNodes as $id => $node) {
            if (isset($disabledSet[$id]) || ($this->activationGate !== null && ! $this->activationGate->isEnabled($id))) {
                $disabledIds[] = $id;

                continue;
            }

            $activeNodes[$id] = $node;
        }

        sort($disabledIds, SORT_STRING);

        return [$activeNodes, $disabledIds];
    }

    /**
     * @param  list<PluginEntry>  $entries
     * @return array<string, PluginNode>
     */
    private function nodes(array $entries): array
    {
        $nodes = [];

        foreach ($entries as $entry) {
            if (! class_exists($entry->class) || ! is_subclass_of($entry->class, ExtensionPlugin::class)) {
                throw new InvalidConfiguration(["{$entry->origin}: {$entry->class} must be a class implementing ".ExtensionPlugin::class]);
            }

            try {
                $plugin = new ($entry->class)();
                $manifest = $plugin->manifest();
                $facets = ExtensionInspector::inspect($plugin);
                $declarations = new PluginDeclarations;
                $registrar = new PluginRegistrar($declarations, $manifest->id);
                $this->applyFacets($registrar, $facets);
                $plugin->register($registrar);
                $declarations->seal();
            } catch (InvalidConfiguration $e) {
                throw new InvalidConfiguration(["{$entry->origin}: {$entry->class}: ".$e->getMessage()]);
            }

            if ($this->database !== null && $manifest->databases !== [] && ! in_array($this->database, $manifest->databases, true)) {
                throw new InvalidConfiguration(["{$entry->origin}: plugin '{$manifest->id}' does not support database driver '{$this->database}'"]);
            }

            if (isset($nodes[$manifest->id])) {
                throw new InvalidConfiguration(["{$entry->origin}: plugin id '{$manifest->id}' is already registered by {$nodes[$manifest->id]->entry->origin}"]);
            }

            $nodes[$manifest->id] = new PluginNode($entry, $plugin, $manifest, $declarations, $facets);
        }

        return $nodes;
    }

    private function applyFacets(PluginRegistrar $registrar, ExtensionFacetProfile $facets): void
    {
        foreach ($facets->definedCapabilities as $contract => $cardinalityValue) {
            $cardinality = Cardinality::tryFrom($cardinalityValue);

            if ($cardinality === null) {
                throw new InvalidConfiguration(["plugin.capability.cardinality: unknown cardinality '{$cardinalityValue}' for {$contract}"]);
            }

            $registrar->defineCapability($contract, $cardinality);
        }

        foreach ($facets->providedCapabilities as $contract => $provider) {
            if (is_string($provider)) {
                $registrar->provides($contract, $provider);

                continue;
            }

            $registrar->providesFromConfig($contract, $provider['config'], $provider['default'] ?? null);
        }

        foreach ($facets->definedSlots as $contract => $spec) {
            $kind = SlotKind::tryFrom($spec['kind']);

            if ($kind === null) {
                throw new InvalidConfiguration(["plugin.slot.kind: unknown kind '{$spec['kind']}' for {$contract}"]);
            }

            $cardinalityValue = $spec['cardinality'] ?? Cardinality::Many->value;
            $cardinality = Cardinality::tryFrom($cardinalityValue);

            if ($cardinality === null) {
                throw new InvalidConfiguration(["plugin.slot.cardinality: unknown cardinality '{$cardinalityValue}' for {$contract}"]);
            }

            $registrar->defineSlot($contract, $kind, $cardinality);
        }

        foreach ($facets->slotContributions as $contribution) {
            $registrar->contributeSlot(
                slot: $contribution['slot'],
                id: $contribution['id'],
                implementation: $contribution['implementation'],
                priority: $contribution['priority'] ?? 0,
                key: $contribution['key'] ?? null,
            );
        }
    }

    /**
     * @param  array<string, PluginNode>  $activeNodes
     * @param  array<string, PluginNode>  $allNodes
     * @return array<string, list<string>>
     */
    private function dependencies(array $activeNodes, array $allNodes, bool $includeOptional = true): array
    {
        $dependencies = [];

        foreach ($activeNodes as $id => $node) {
            foreach ($node->manifest->requires as $required) {
                if (! isset($allNodes[$required])) {
                    throw $this->fail($node, "requires '{$required}', which is not registered");
                }

                if (! isset($activeNodes[$required])) {
                    throw $this->fail($node, "requires '{$required}', which is disabled");
                }
            }

            $after = $node->manifest->requires;

            foreach ($includeOptional ? $node->manifest->optional : [] as $optional) {
                if (isset($activeNodes[$optional])) {
                    $after[] = $optional;
                }
            }

            if ($this->corePluginId !== '' && $id !== $this->corePluginId && isset($activeNodes[$this->corePluginId])) {
                $after[] = $this->corePluginId;
            }

            $dependencies[$id] = array_values(array_unique($after));
        }

        return $dependencies;
    }

    /**
     * @param  array<string, PluginNode>  $nodes
     * @param  array<string, list<string>>  $dependencies
     * @return list<string>
     */
    private function order(array $nodes, array $dependencies): array
    {
        $remaining = $dependencies;
        $order = [];

        while ($remaining !== []) {
            $ready = array_keys(array_filter($remaining, static fn (array $after): bool => array_diff($after, $order) === []));

            if ($ready === []) {
                $cycle = array_keys($remaining);
                sort($cycle, SORT_STRING);

                throw $this->fail($nodes[$cycle[0]], 'dependency cycle among '.implode(', ', $cycle));
            }

            sort($ready, SORT_STRING);
            $next = $ready[0];
            $order[] = $next;
            unset($remaining[$next]);
        }

        return $order;
    }

    /**
     * @param  list<string>  $order
     * @param  array<string, list<string>>  $dependencies
     * @return array<string, array<string, true>>
     */
    private function ancestors(array $order, array $dependencies): array
    {
        $ancestors = [];

        foreach ($order as $id) {
            $ancestors[$id] = [];

            foreach ($dependencies[$id] as $dependency) {
                $ancestors[$id][$dependency] = true;
                $ancestors[$id] += $ancestors[$dependency];
            }
        }

        return $ancestors;
    }

    /**
     * @param  array<string, PluginNode>  $nodes
     * @param  list<string>  $order
     * @param  array<string, array<string, true>>  $ancestors
     * @return array{0: array<string, ContributionKind>, 1: array<string, array<string, object>>, 2: array<string, list<ContributionDescription>>}
     */
    private function compileKindsAndItems(array $nodes, array $order, array $ancestors): array
    {
        $kinds = [];
        $declaredBy = [];

        foreach ($order as $id) {
            foreach ($nodes[$id]->declarations->kinds() as $kind) {
                if (isset($kinds[$kind->name])) {
                    throw $this->fail($nodes[$id], "kind '{$kind->name}' is already declared by '{$declaredBy[$kind->name]}'");
                }

                $kinds[$kind->name] = $kind;
                $declaredBy[$kind->name] = $id;
            }
        }

        $items = [];
        $owners = [];
        $byPlugin = [];

        foreach ($order as $id) {
            $node = $nodes[$id];
            $byPlugin[$id] = [];

            foreach ($node->declarations->contributions() as $declared) {
                $kind = $kinds[$declared['kind']] ?? throw $this->fail($node, "contributes to unknown kind '{$declared['kind']}'");

                if ($kind->ownerPluginId !== $id && ! isset($ancestors[$id][$kind->ownerPluginId])) {
                    throw $this->fail($node, "kind '{$kind->name}' belongs to '{$kind->ownerPluginId}', which this plugin does not require");
                }

                $contribution = $this->instance($node, $kind, $declared['contribution']);
                $key = $this->key($node, $kind, $contribution);

                if (isset($items[$kind->name][$key])) {
                    throw $this->fail($node, "{$kind->name}/{$key} is already contributed by '{$owners[$kind->name][$key]}'");
                }

                $items[$kind->name][$key] = $contribution;
                $owners[$kind->name][$key] = $id;
                $byPlugin[$id][] = new ContributionDescription($kind->name, $key, $contribution::class);
            }
        }

        return [$kinds, $items, $byPlugin];
    }

    /**
     * @param  array<string, PluginNode>  $nodes
     * @param  list<string>  $order
     * @param  array<string, array<string, true>>  $ancestors
     * @return array{0: array<class-string, CapabilityDefinition>, 1: array<class-string, list<CapabilityProvider>>}
     */
    private function compileCapabilities(array $nodes, array $order, array $ancestors): array
    {
        $definitions = [];

        foreach ($order as $id) {
            foreach ($nodes[$id]->declarations->capabilityDefinitions() as $definition) {
                if (isset($definitions[$definition->contract])) {
                    throw $this->fail($nodes[$id], sprintf('capability %s is defined by both "%s" and "%s"', $definition->contract, $definitions[$definition->contract]->pluginId, $id));
                }

                $definitions[$definition->contract] = $definition;
            }
        }

        $providers = [];

        foreach ($order as $id) {
            $node = $nodes[$id];

            foreach ($node->declarations->capabilityProviders() as $provider) {
                $definition = $definitions[$provider->contract] ?? throw $this->fail($node, sprintf('provides %s, which no plugin defines', $provider->contract));

                if ($definition->pluginId !== $id && ! isset($ancestors[$id][$definition->pluginId])) {
                    throw $this->fail($node, sprintf('provides %s but does not require "%s", which defines it', $provider->contract, $definition->pluginId));
                }

                foreach ([$provider->implementation, $provider->default] as $class) {
                    if ($class !== null && ! is_a($class, $provider->contract, true)) {
                        throw $this->fail($node, sprintf('provides %s with %s, which does not implement it', $provider->contract, $class));
                    }
                }

                $providers[$provider->contract][] = $provider;
            }
        }

        foreach ($definitions as $contract => $definition) {
            $count = count($providers[$contract] ?? []);

            if (! $definition->cardinality->admits($count)) {
                throw $this->fail($nodes[$definition->pluginId], sprintf('capability %s (%s) has %d active providers', $contract, $definition->cardinality->value, $count));
            }
        }

        ksort($definitions, SORT_STRING);
        ksort($providers, SORT_STRING);

        return [$definitions, $providers];
    }

    /**
     * @param  array<string, PluginNode>  $nodes
     * @param  list<string>  $order
     * @param  array<string, array<string, true>>  $ancestors
     * @return array{0: array<class-string, SlotDefinition>, 1: array<class-string, list<SlotContribution>>}
     */
    private function compileSlots(array $nodes, array $order, array $ancestors): array
    {
        $slots = [];

        foreach ($order as $id) {
            foreach ($nodes[$id]->declarations->slotDefinitions() as $slot) {
                if (isset($slots[$slot->contract])) {
                    throw $this->fail($nodes[$id], sprintf('slot %s is defined by both "%s" and "%s"', $slot->contract, $slots[$slot->contract]->pluginId, $id));
                }

                $slots[$slot->contract] = $slot;
            }
        }

        $contributions = [];

        foreach ($order as $id) {
            $node = $nodes[$id];

            foreach ($node->declarations->slotContributions() as $contribution) {
                $slot = $slots[$contribution->slot] ?? throw $this->fail($node, sprintf('contributes "%s" to the unknown slot %s', $contribution->id, $contribution->slot));

                if (preg_match(self::CONTRIBUTION_ID_PATTERN, $contribution->id) !== 1) {
                    throw $this->fail($node, sprintf('contributes to %s under the malformed id "%s"', $contribution->slot, $contribution->id));
                }

                if ($slot->pluginId !== $id && ! isset($ancestors[$id][$slot->pluginId])) {
                    throw $this->fail($node, sprintf('contributes "%s" to %s but does not require "%s", which defines it', $contribution->id, $contribution->slot, $slot->pluginId));
                }

                if (! is_a($contribution->implementation, $contribution->slot, true)) {
                    throw $this->fail($node, sprintf('contribution "%s" to %s is %s, which does not implement it', $contribution->id, $contribution->slot, $contribution->implementation));
                }

                if ($slot->kind === SlotKind::Handler && ($contribution->key === null || $contribution->key === '')) {
                    throw $this->fail($node, sprintf('contribution "%s" to the handler slot %s has no key', $contribution->id, $contribution->slot));
                }

                $contributions[$contribution->slot][] = $contribution;
            }
        }

        foreach ($slots as $contract => $slot) {
            $list = $contributions[$contract] ?? [];
            usort($list, static fn (SlotContribution $a, SlotContribution $b): int => $a->priority !== $b->priority ? $a->priority <=> $b->priority : strcmp($a->id, $b->id));
            $contributions[$contract] = $list;

            $seenIds = [];
            $seenKeys = [];

            foreach ($list as $c) {
                if (isset($seenIds[$c->id])) {
                    throw $this->fail($nodes[$c->pluginId], sprintf('slot %s has the duplicate contribution id "%s"', $contract, $c->id));
                }
                $seenIds[$c->id] = true;

                if ($c->key !== null) {
                    if (isset($seenKeys[$c->key])) {
                        throw $this->fail($nodes[$c->pluginId], sprintf('slot %s has the duplicate key "%s"', $contract, $c->key));
                    }
                    $seenKeys[$c->key] = true;
                }
            }

            if (! $slot->cardinality->admits(count($list))) {
                throw $this->fail($nodes[$slot->pluginId], sprintf('slot %s (%s) has %d active contributions', $contract, $slot->cardinality->value, count($list)));
            }
        }

        ksort($slots, SORT_STRING);
        ksort($contributions, SORT_STRING);

        return [$slots, $contributions];
    }

    /** @param object|class-string $declared */
    private function instance(PluginNode $node, ContributionKind $kind, object|string $declared): object
    {
        if (is_object($declared)) {
            $contribution = $declared;
        } else {
            if (! class_exists($declared)) {
                throw $this->fail($node, "{$kind->name} contribution {$declared} is not a class");
            }

            try {
                $made = $this->container->make($declared);
            } catch (Throwable $e) {
                throw $this->fail($node, "cannot create {$kind->name} contribution {$declared}: {$e->getMessage()}");
            }

            $contribution = is_object($made) ? $made : throw $this->fail($node, "{$kind->name} contribution {$declared} did not resolve to an object");
        }

        if (! $contribution instanceof $kind->interface) {
            throw $this->fail($node, "{$kind->name} contribution ".$contribution::class." must implement {$kind->interface}");
        }

        return $contribution;
    }

    private function key(PluginNode $node, ContributionKind $kind, object $contribution): string
    {
        $key = method_exists($contribution, 'key') ? $contribution->key() : null;

        if (! is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw $this->fail($node, "{$kind->name} contribution ".$contribution::class.' must have a key() returning a lowercase dotted key');
        }

        return $key;
    }

    private function fail(PluginNode $node, string $reason): InvalidConfiguration
    {
        return new InvalidConfiguration(["{$node->entry->origin}: plugin '{$node->manifest->id}' ({$node->entry->class}): {$reason}"]);
    }
}
