<?php

declare(strict_types=1);

namespace Packman\Config\Laravel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Config\Contracts\ConfigSection;
use Packman\Config\Contracts\SettingsLookup;
use Packman\Config\Data\CompileContext;
use Packman\Config\Internal\ConfigNormalizer;
use Packman\Config\Internal\ConfigSections;
use Packman\Config\Internal\SectionCompiler;
use Packman\Config\Internal\SectionRegistrar;
use Packman\Foundation\Contracts\PackageRegistrar;

#[Spi(kind: SpiKind::Call, since: '1.0')]
final class ConfigRegistrar implements PackageRegistrar
{
    #[Override]
    public function register(Application $app): void
    {
        $app->singleton(ConfigSections::class);
        $app->singleton(ConfigNormalizer::class);
        $app->singleton(SectionCompiler::class);
        $app->bind(ConfigurationValidator::class);

        $app->singletonIf(SettingsLookup::class, static function (Application $app): SettingsLookup {
            /** @var list<ConfigSection> $sections */
            $sections = [];

            foreach ($app->tagged(SectionRegistrar::TAG) as $section) {
                if ($section instanceof ConfigSection) {
                    $sections[] = $section;
                }
            }

            $repo = $app->bound(Repository::class) ? $app->make(Repository::class) : null;
            /** @var array<string, mixed> $roots */
            $roots = $repo instanceof Repository ? $repo->all() : [];
            $defaultConn = $repo instanceof Repository ? $repo->get('database.default', 'sqlite') : 'sqlite';
            $connectionsRaw = $repo instanceof Repository ? $repo->get('database.connections', []) : [];
            /** @var list<string> $connections */
            $connections = is_array($connectionsRaw) ? array_map(strval(...), array_keys($connectionsRaw)) : [];
            $userModel = $repo instanceof Repository ? $repo->get('auth.providers.users.model') : null;
            $allowUnsafe = $repo instanceof Repository && $repo->get('packman.unsafe.allow_in_production') === true;
            $environment = $app->environment();

            return $app->make(SectionCompiler::class)->compile(
                sections: $sections,
                roots: $roots,
                context: new CompileContext(
                    environment: $environment,
                    defaultConnection: is_string($defaultConn) && $defaultConn !== '' ? $defaultConn : 'sqlite',
                    connections: $connections !== [] ? $connections : ['sqlite'],
                    userModelFallback: is_string($userModel) ? $userModel : null,
                    allowUnsafe: $allowUnsafe,
                ),
            );
        });
    }

    #[Override]
    public function boot(Application $app): void
    {
        RuntimeConfigGate::install($app);
    }
}
