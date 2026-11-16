<?php

declare(strict_types=1);

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Application as FoundationApplication;
use Illuminate\Support\ServiceProvider;
use Packman\Foundation\ApplicationLocator;
use Packman\Foundation\Concerns\RegistersNothingOnBoot;
use Packman\Foundation\Concerns\RegistersPackageRegistrars;
use Packman\Foundation\Contracts\PackageRegistrar;

it('records register and boots as a no-op', function (): void {
    $registrar = new class implements PackageRegistrar
    {
        use RegistersNothingOnBoot;

        public bool $registered = false;

        public function register(Application $app): void
        {
            $this->registered = true;
        }
    };

    $app = new FoundationApplication(dirname(__DIR__, 4));

    $registrar->register($app);
    $registrar->boot($app);

    expect($registrar->registered)->toBeTrue();
});

it('composes registrars in order via RegistersPackageRegistrars', function (): void {
    $app = new FoundationApplication(dirname(__DIR__, 4));
    $app->instance('log.order', []);

    $provider = new class($app) extends ServiceProvider
    {
        use RegistersPackageRegistrars;

        protected const array REGISTRARS = [
            StubFirstRegistrar::class,
            StubSecondRegistrar::class,
        ];
    };

    $provider->register();
    $provider->boot();

    expect($app->make('log.order'))->toBe(['first:register', 'second:register', 'first:boot', 'second:boot']);
});

it('locates the active application container via ApplicationLocator', function (): void {
    $app = new FoundationApplication(dirname(__DIR__, 4));
    FoundationApplication::setInstance($app);

    expect(ApplicationLocator::current())->toBe($app);
});

final class StubFirstRegistrar implements PackageRegistrar
{
    public function register(Application $app): void
    {
        $log = $app->make('log.order');
        $log[] = 'first:register';
        $app->instance('log.order', $log);
    }

    public function boot(Application $app): void
    {
        $log = $app->make('log.order');
        $log[] = 'first:boot';
        $app->instance('log.order', $log);
    }
}

final class StubSecondRegistrar implements PackageRegistrar
{
    public function register(Application $app): void
    {
        $log = $app->make('log.order');
        $log[] = 'second:register';
        $app->instance('log.order', $log);
    }

    public function boot(Application $app): void
    {
        $log = $app->make('log.order');
        $log[] = 'second:boot';
        $app->instance('log.order', $log);
    }
}
