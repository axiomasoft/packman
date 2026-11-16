<?php

declare(strict_types=1);

namespace Packman\Modules\Internal;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Packman\Modules\Contracts\ModuleRegistrar;

/**
 * Lightweight, own module loader: a module's top-level provider lists its
 * child providers, which are registered through the application container.
 * Top-level providers are still discovered by Laravel package auto-discovery.
 */
final class ServiceProviderModuleRegistrar implements ModuleRegistrar
{
    /** @var list<class-string> */
    private array $registered = [];

    /** @param  Closure(): Application  $application  resolves the running application when a provider is registered. */
    public function __construct(
        private readonly Closure $application,
    ) {}

    public function register(string ...$providers): void
    {
        foreach ($providers as $provider) {
            ($this->application)()->register($provider);
            $this->registered[] = $provider;
        }
    }

    public function registered(): array
    {
        return $this->registered;
    }
}
