<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Packman\Plugins\Contracts\ExtensionPlugin;
use Packman\Plugins\Data\PluginManifest;
use Packman\Plugins\Data\SlotContribution;
use Packman\Plugins\Enums\Cardinality;
use Packman\Plugins\Enums\SlotKind;
use Packman\Plugins\Exceptions\MissingExtensionException;
use Packman\Plugins\Internal\ExtensionRegistry;
use Packman\Plugins\Internal\PluginEntry;
use Packman\Plugins\Internal\PluginGraphCompiler;
use Packman\Plugins\Laravel\PluginRegistrar;
use Packman\Plugins\Tests\Fixtures\FirstStep;
use Packman\Plugins\Tests\Fixtures\Greeter;
use Packman\Plugins\Tests\Fixtures\PipelineStep;
use Packman\Plugins\Tests\Fixtures\SecondStep;
use Packman\Plugins\Tests\Fixtures\ThirdStep;

require_once __DIR__.'/../Fixtures/TestContracts.php';

final class SlotHostPlugin implements ExtensionPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest('slot.host', '1.0.0');
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->defineSlot(PipelineStep::class, SlotKind::Handler, Cardinality::Many)
            ->contributeSlot(PipelineStep::class, 'host.third', ThirdStep::class, priority: 30, key: 'third');
    }
}

final class SlotFirstPlugin implements ExtensionPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest('slot.first', '1.0.0', requires: ['slot.host']);
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->contributeSlot(PipelineStep::class, 'plugin.first', FirstStep::class, priority: 10, key: 'first');
    }
}

final class SlotSecondPlugin implements ExtensionPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest('slot.second', '1.0.0', requires: ['slot.host']);
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->contributeSlot(PipelineStep::class, 'optional.second', SecondStep::class, priority: 20, key: 'second');
    }
}

/** @param list<class-string<ExtensionPlugin>> $plugins */
function buildExtensionRegistry(array $plugins): ExtensionRegistry
{
    $container = new Container;
    $entries = array_map(static fn (string $class, int $idx): PluginEntry => PluginEntry::config($class, $idx), $plugins, array_keys($plugins));
    $compiled = (new PluginGraphCompiler($container))->compile($entries);

    return new ExtensionRegistry($compiled, $container);
}

it('lists active contributions of a slot sorted by priority then id', function (): void {
    $ids = static fn (ExtensionRegistry $r): array => array_map(static fn (SlotContribution $c): string => $c->id, $r->contributions(PipelineStep::class));

    $two = buildExtensionRegistry([SlotHostPlugin::class, SlotFirstPlugin::class]);
    $three = buildExtensionRegistry([SlotFirstPlugin::class, SlotSecondPlugin::class, SlotHostPlugin::class]);

    expect($ids($two))->toBe(['plugin.first', 'host.third'])
        ->and($ids($three))->toBe(['plugin.first', 'optional.second', 'host.third'])
        ->and($two->contributions(PipelineStep::class)[0])->toEqual(new SlotContribution(PipelineStep::class, 'plugin.first', 'slot.first', FirstStep::class, 10, 'first'));
});

it('resolves slot contributions in deterministic priority order', function (): void {
    $registry = buildExtensionRegistry([SlotSecondPlugin::class, SlotHostPlugin::class, SlotFirstPlugin::class]);

    expect(array_map(static fn (PipelineStep $s): string => $s->name(), $registry->resolve(PipelineStep::class)))
        ->toBe(['first', 'second', 'third']);
});

it('serves handlers by key, returns handler classes, and fails closed on missing keys', function (): void {
    $registry = buildExtensionRegistry([SlotHostPlugin::class, SlotFirstPlugin::class]);

    expect($registry->handlerKeys(PipelineStep::class))->toBe(['first', 'third'])
        ->and($registry->handler(PipelineStep::class, 'third'))->toBeInstanceOf(ThirdStep::class)
        ->and($registry->handlerClass(PipelineStep::class, 'first'))->toBe(FirstStep::class)
        ->and(static fn (): object => $registry->handler(PipelineStep::class, 'second'))->toThrow(MissingExtensionException::class, '"second"');
});

it('returns empty contributions for an undefined slot', function (): void {
    $registry = buildExtensionRegistry([SlotHostPlugin::class]);

    expect($registry->contributions(Greeter::class))->toBe([])
        ->and(buildExtensionRegistry([])->contributions(PipelineStep::class))->toBe([]);
});
