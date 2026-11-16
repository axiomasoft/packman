<?php

declare(strict_types=1);

namespace Packman\Panels\Internal\Configuration;

use Override;
use Packman\Attributes\Internal;
use Packman\Config\Contracts\OptionsFactory;
use Packman\Config\Laravel\ConfigurationValidator;

/**
 * Compiled configuration snapshot for `axiomasoft/packman-panels`.
 */
#[Internal]
final readonly class PanelsOptions implements OptionsFactory
{
    /**
     * @param  array<string, bool|int|string|null>  $defaults
     */
    public function __construct(
        public string $providerTag = 'packman.panels.providers',
        public ?string $viewsConnection = null,
        public string $viewsTable = 'sys_saved_views',
        public string $scopesTable = 'sys_saved_view_scopes',
        public array $defaults = [],
    ) {}

    /** @param array<array-key, mixed> $config */
    #[Override]
    public static function fromArray(array $config, string $path = 'packman-panels'): self
    {
        $violations = new ConfigurationValidator;

        $providerTag = $config['provider_tag'] ?? 'packman.panels.providers';

        if (! is_string($providerTag) || $providerTag === '') {
            $violations->add(path: 'provider_tag', message: 'must be a non-empty string');
            $providerTag = 'packman.panels.providers';
        }

        $views = $config['views'] ?? [];

        if (! is_array($views)) {
            $violations->add(path: 'views', message: 'must be an array');
            $views = [];
        }

        $viewsConnection = $views['connection'] ?? null;

        if ($viewsConnection !== null && (! is_string($viewsConnection) || $viewsConnection === '')) {
            $violations->add(path: 'views.connection', message: 'must be a non-empty string or null');
            $viewsConnection = null;
        }

        $viewsTable = $views['table'] ?? 'sys_saved_views';

        if (! is_string($viewsTable) || $viewsTable === '') {
            $violations->add(path: 'views.table', message: 'must be a non-empty string');
            $viewsTable = 'sys_saved_views';
        }

        $scopesTable = $views['scopes_table'] ?? 'sys_saved_view_scopes';

        if (! is_string($scopesTable) || $scopesTable === '') {
            $violations->add(path: 'views.scopes_table', message: 'must be a non-empty string');
            $scopesTable = 'sys_saved_view_scopes';
        }

        $defaults = self::extractDefaults($config['defaults'] ?? [], $violations);

        $violations->failIfAny();

        return new self(
            providerTag: $providerTag,
            viewsConnection: $viewsConnection,
            viewsTable: $viewsTable,
            scopesTable: $scopesTable,
            defaults: $defaults,
        );
    }

    /**
     * @return array<string, bool|int|string|null>
     */
    private static function extractDefaults(mixed $raw, ConfigurationValidator $violations): array
    {
        if (! is_array($raw)) {
            $violations->add(path: 'defaults', message: 'must be an array');

            return [];
        }

        /** @var array<string, bool|int|string|null> $defaults */
        $defaults = [];

        if (array_key_exists('resource_prefix', $raw)) {
            $prefix = $raw['resource_prefix'];

            if (is_bool($prefix) || (is_string($prefix) && $prefix !== '')) {
                $defaults['resource_prefix'] = $prefix;
            } else {
                $violations->add(path: 'defaults.resource_prefix', message: 'must be a boolean or non-empty string');
            }
        }

        if (isset($raw['cache'])) {
            if (! is_array($raw['cache'])) {
                $violations->add(path: 'defaults.cache', message: 'must be an array');
            } else {
                $cache = $raw['cache'];

                if (array_key_exists('store', $cache)) {
                    $store = $cache['store'];

                    if ($store === null || (is_string($store) && $store !== '')) {
                        $defaults['cache.store'] = $store;
                    } else {
                        $violations->add(path: 'defaults.cache.store', message: 'must be a non-empty string or null');
                    }
                }

                if (array_key_exists('ttl', $cache)) {
                    $ttl = $cache['ttl'];

                    if ($ttl === null || (is_int($ttl) && $ttl >= 1)) {
                        $defaults['cache.ttl'] = $ttl;
                    } else {
                        $violations->add(path: 'defaults.cache.ttl', message: 'must be a positive integer or null');
                    }
                }

                if (array_key_exists('generation', $cache)) {
                    $generation = $cache['generation'];

                    if (is_int($generation) && $generation >= 1) {
                        $defaults['cache.generation'] = $generation;
                    } else {
                        $violations->add(path: 'defaults.cache.generation', message: 'must be a positive integer');
                    }
                }
            }
        }

        if (isset($raw['consistency'])) {
            if (! is_array($raw['consistency'])) {
                $violations->add(path: 'defaults.consistency', message: 'must be an array');
            } else {
                $consistency = $raw['consistency'];

                if (array_key_exists('reads', $consistency)) {
                    $reads = $consistency['reads'];

                    if (is_string($reads) && in_array($reads, ['primary', 'default'], true)) {
                        $defaults['consistency.reads'] = $reads;
                    } else {
                        $violations->add(path: 'defaults.consistency.reads', message: 'must be "primary" or "default"');
                    }
                }

                if (array_key_exists('state_refresh', $consistency)) {
                    $stateRefresh = $consistency['state_refresh'];

                    if (is_string($stateRefresh) && in_array($stateRefresh, ['request', 'check'], true)) {
                        $defaults['consistency.state_refresh'] = $stateRefresh;
                    } else {
                        $violations->add(path: 'defaults.consistency.state_refresh', message: 'must be "request" or "check"');
                    }
                }
            }
        }

        if (array_key_exists('trace_decisions', $raw)) {
            $trace = $raw['trace_decisions'];

            if (is_bool($trace)) {
                $defaults['trace_decisions'] = $trace;
            } else {
                $violations->add(path: 'defaults.trace_decisions', message: 'must be a boolean');
            }
        }

        return $defaults;
    }
}
