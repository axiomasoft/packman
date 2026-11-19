<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Exceptions\Registry\RegistryFrozenException;
use Packman\Plugins\Internal\Graph\PluginCatalog;
use Packman\Plugins\Internal\Graph\PluginDeclarations;
use Packman\Plugins\Internal\Graph\PluginEntry;
use Packman\Plugins\Internal\Graph\PluginGraphCompiler;
use Packman\Plugins\Laravel\ExtensionRegistrar;
use Packman\Plugins\Laravel\PluginRegistrar;
use Packman\Plugins\Tests\Fixtures\Greeter;
use Packman\Plugins\Tests\Fixtures\HelloGreeter;

require_once __DIR__.'/../Fixtures/TestContracts.php';

final class PgOnlyPlugin implements ExtensionPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest('acme.pgonly', '1.0.0', databases: ['pgsql']);
    }

    public function register(PluginRegistrar $registrar): void {}
}

final class OtherPlugin implements ExtensionPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest('acme.other');
    }

    public function register(PluginRegistrar $registrar): void {}
}

it('seals PluginCatalog once and keeps deduplicated registration order', function (): void {
    $catalog = new PluginCatalog;
    $catalog->builtIn(PgOnlyPlugin::class);
    $catalog->builtIn(PgOnlyPlugin::class);
    $catalog->compile(new Container, [], []);
    $catalog->builtIn(PgOnlyPlugin::class);

    expect($catalog->entries())->toHaveCount(1)
        ->and(static fn () => $catalog->builtIn(OtherPlugin::class))->toThrow(RegistryFrozenException::class, 'frozen');
});

it('keeps built-in plugins local to the supplied container', function (): void {
    $first = new Container;
    $second = new Container;
    ExtensionRegistrar::builtInFor($first, PgOnlyPlugin::class);

    expect($first->make(PluginCatalog::class)->builtInClasses())->toBe([PgOnlyPlugin::class])
        ->and($second->bound(PluginCatalog::class))->toBeFalse();

    $first->make(PluginCatalog::class)->compile($first, [], []);
    ExtensionRegistrar::builtInFor($first, PgOnlyPlugin::class);
    expect(static fn () => ExtensionRegistrar::builtInFor($first, OtherPlugin::class))->toThrow(RegistryFrozenException::class);

    ExtensionRegistrar::builtInFor($second, PgOnlyPlugin::class);
    expect($second->make(PluginCatalog::class)->builtInClasses())->toBe([PgOnlyPlugin::class]);
});

it('rejects PluginRegistrar mutations after PluginDeclarations are sealed', function (): void {
    $declarations = new PluginDeclarations;
    $registrar = new PluginRegistrar($declarations, 'acme.test');
    $declarations->seal();

    expect(static fn () => $registrar->defineCapability(Greeter::class))->toThrow(RegistryFrozenException::class, 'frozen')
        ->and(static fn () => $registrar->provides(Greeter::class, HelloGreeter::class))->toThrow(LogicException::class, 'frozen');
});

it('validates optional resourceRoot and database matrix in PluginManifest', function (): void {
    expect(static fn () => new PluginManifest('acme.bad', resourceRoot: 'relative/path'))->toThrow(InvalidConfiguration::class, 'resourceRoot')
        ->and(static fn () => new PluginManifest('acme.bad', resourceRoot: '/tmp/../escape'))->toThrow(InvalidConfiguration::class, 'resourceRoot')
        ->and(static fn () => new PluginManifest('acme.bad', databases: ['oracle']))->toThrow(InvalidConfiguration::class, 'oracle')
        ->and(new PluginManifest('acme.good', resourceRoot: __DIR__, databases: ['pgsql', 'sqlite'])->databases)->toBe(['pgsql', 'sqlite']);
});

it('rejects plugins incompatible with target database engine during compilation', function (): void {
    $compiler = new PluginGraphCompiler(new Container, database: 'sqlite');

    $entries = [PluginEntry::config(PgOnlyPlugin::class, 0)];
    expect($compiler->compile($entries, ['acme.pgonly'])->disabledIds())->toBe(['acme.pgonly'])
        ->and(static fn () => $compiler->compile($entries))
        ->toThrow(InvalidConfiguration::class, "does not support database driver 'sqlite'");
});
