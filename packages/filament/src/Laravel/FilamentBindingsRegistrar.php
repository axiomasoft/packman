<?php

declare(strict_types=1);

namespace Packman\Filament\Laravel;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Filament\Contracts\FilamentSurfaceGate;
use Packman\Filament\Forms\BindingEditor;
use Packman\Filament\Internal\Configuration\FilamentOptions;
use Packman\Filament\Internal\Surface\DefaultFilamentSurfaceGate;
use Packman\Filament\PackmanFilamentPlugin;
use Packman\Foundation\Contracts\PackageRegistrar;
use Packman\Panels\Contracts\Admission\PanelAdmissionGate;
use Packman\Panels\Contracts\Admission\PanelAdmitter;
use Packman\Panels\Contracts\Registry\PanelRegistry;
use Packman\Panels\Contracts\Subjects\PanelFormProjector;
use Packman\Panels\Contracts\Subjects\SubjectChainResolver;

/**
 * Service container composition for `axiomasoft/packman-filament`.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class FilamentBindingsRegistrar implements PackageRegistrar
{
    #[Override]
    public function register(Application $app): void
    {
        $app->singleton(FilamentOptions::class, static function (Application $app): FilamentOptions {
            $config = $app->make(Repository::class);
            $primary = (array) $config->get('packman-filament', []);
            $scoped = (array) $config->get('packman.filament', []);
            /** @var array<string, mixed> $merged */
            $merged = array_replace_recursive($primary, $scoped);

            return FilamentOptions::fromArray($merged);
        });

        $app->singleton(FilamentSurfaceGate::class, static fn (Application $app): FilamentSurfaceGate => new DefaultFilamentSurfaceGate(
            gate: $app->make(Gate::class),
            panels: $app->bound(PanelRegistry::class) ? $app->make(PanelRegistry::class) : null,
            admission: $app->bound(PanelAdmissionGate::class) && $app->bound(PanelAdmitter::class)
                ? $app->make(PanelAdmitter::class)
                : null,
            container: $app,
        ));

        $app->bind(PackmanFilamentPlugin::class, static fn (Application $app): PackmanFilamentPlugin => new PackmanFilamentPlugin(
            options: $app->make(FilamentOptions::class),
        ));

        $app->bind(BindingEditor::class, static fn (Application $app): BindingEditor => new BindingEditor(
            panels: $app->make(PanelRegistry::class),
            projector: $app->make(PanelFormProjector::class),
            subjects: $app->make(SubjectChainResolver::class),
        ));
    }

    #[Override]
    public function boot(Application $app): void
    {
        $app->make(FilamentOptions::class);
    }
}
