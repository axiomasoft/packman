<?php

declare(strict_types=1);

use Packman\Plugins\Contracts\Registry\PluginActivationGate;
use Packman\Plugins\Internal\Graph\CombinedActivationGate;

function gateReturning(bool $enabled): PluginActivationGate
{
    return new class($enabled) implements PluginActivationGate
    {
        public function __construct(private bool $enabled) {}

        public function isEnabled(string $pluginId): bool
        {
            return $this->enabled;
        }
    };
}

it('enables a plugin only when every composed gate allows it', function (): void {
    expect((new CombinedActivationGate([]))->isEnabled('reports'))->toBeTrue()
        ->and((new CombinedActivationGate([gateReturning(true), gateReturning(true)]))->isEnabled('reports'))->toBeTrue()
        ->and((new CombinedActivationGate([gateReturning(true), gateReturning(false)]))->isEnabled('reports'))->toBeFalse();
});
