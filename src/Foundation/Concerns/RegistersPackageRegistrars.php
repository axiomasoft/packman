<?php

declare(strict_types=1);

namespace Packman\Foundation\Concerns;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Foundation\Contracts\PackageRegistrar;

/**
 * Thin ServiceProvider composition helper iterating `protected const array REGISTRARS = [...]`.
 *
 * Keeps providers concise and free of I/O in `register()`.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
trait RegistersPackageRegistrars
{
    public function register(): void
    {
        $this->registerPackageRegistrars();
    }

    public function boot(): void
    {
        $this->bootPackageRegistrars();
    }

    protected function registerPackageRegistrars(): void
    {
        foreach ($this->resolvePackageRegistrars() as $registrarClass) {
            (new $registrarClass)->register($this->app);
        }
    }

    protected function bootPackageRegistrars(): void
    {
        foreach ($this->resolvePackageRegistrars() as $registrarClass) {
            (new $registrarClass)->boot($this->app);
        }
    }

    /** @return list<class-string<PackageRegistrar>> */
    protected function resolvePackageRegistrars(): array
    {
        $constant = static::class.'::REGISTRARS';

        return defined($constant) ? constant($constant) : [];
    }
}
