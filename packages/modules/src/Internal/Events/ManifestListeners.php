<?php

declare(strict_types=1);

namespace Packman\Modules\Internal\Events;

use Illuminate\Events\Dispatcher;
use Packman\Context\Contracts\ContextResolverInterface;
use Packman\Context\Exceptions\ScopeUnavailableException;
use Packman\Modules\Contracts\ModuleRegistry;
use Packman\Modules\Data\CompiledRegistry;

final class ManifestListeners
{
    /** @var array<string, true> */
    private array $registered = [];

    public function __construct(
        private readonly Dispatcher $events,
        private readonly ModuleRegistry $modules,
        private readonly ContextResolverInterface $context,
    ) {}

    public function register(CompiledRegistry $registry): void
    {
        foreach ($registry->listeners as $module => $events) {
            foreach ($events as $event => $listeners) {
                foreach (array_unique($listeners) as $listener) {
                    $key = $module.'|'.$event.'|'.$listener;

                    if (isset($this->registered[$key])) {
                        continue;
                    }
                    $this->registered[$key] = true;
                    $invoke = $this->events->makeListener($listener);
                    $this->events->listen($event, function (...$payload) use ($module, $event, $listener, $invoke): mixed {
                        try {
                            $context = $this->context->current();
                        } catch (ScopeUnavailableException) {
                            return null;
                        }

                        if ($context === null) {
                            return null;
                        }

                        if (! $this->modules->isActive(name: $module, ctx: $context)
                            || ! in_array($listener, $this->modules->compiled()->listeners[$module][$event] ?? [], true)) {
                            return null;
                        }

                        return $invoke($event, $payload);
                    });
                }
            }
        }
    }
}
