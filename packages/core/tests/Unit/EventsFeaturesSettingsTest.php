<?php

declare(strict_types=1);

use Packman\Context\Data\ScopeRef;
use Packman\Data\Laravel\JsonDocuments;
use Packman\Events\DomainEvent;
use Packman\Features\Contracts\FeatureFlagsInterface;
use Packman\Features\Data\FeatureFlagDefinition;
use Packman\Features\Internal\NullFeatureFlags;
use Packman\Settings\Contracts\SettingDefaultsProviderInterface;

it('constructs DomainEvent with UUIDv7, UTC timestamp, and optional scopeId', function (): void {
    $event = new readonly class(new ScopeRef('acct-9')) extends DomainEvent
    {
        public static function name(): string
        {
            return 'packman.sample.occurred';
        }
    };

    expect($event::name())->toBe('packman.sample.occurred')
        ->and($event->scopeId)->toBe('acct-9')
        ->and($event->occurredAt->getTimezone()->getName())->toBe('UTC')
        ->and($event->eventId)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');
});

it('builds immutable FeatureFlagDefinition and fulfills FeatureFlagsInterface', function (): void {
    $def = FeatureFlagDefinition::make('billing.enabled', true)
        ->module('acme/billing')
        ->payload(JsonDocuments::capture(['tier' => 'pro']));

    $store = new class implements FeatureFlagsInterface
    {
        /** @var list<FeatureFlagDefinition> */
        public array $defined = [];

        public function define(FeatureFlagDefinition $def): void
        {
            $this->defined[] = $def;
        }
    };

    $store->define($def);

    expect($store->defined)->toHaveCount(1)
        ->and($def->key)->toBe('billing.enabled')
        ->and($def->default)->toBeTrue()
        ->and($def->module)->toBe('acme/billing');

    (new NullFeatureFlags)->define($def);
});

it('supports SettingDefaultsProviderInterface lookups', function (): void {
    $provider = new class implements SettingDefaultsProviderInterface
    {
        public function defaultFor(string $namespace, string $key): mixed
        {
            return $namespace === 'mail' && $key === 'host' ? 'localhost' : null;
        }

        public function isSensitive(string $namespace, string $key): bool
        {
            return $key === 'api_key';
        }
    };

    expect($provider->defaultFor('mail', 'host'))->toBe('localhost')
        ->and($provider->isSensitive('mail', 'api_key'))->toBeTrue();
});
