<?php

declare(strict_types=1);

namespace Packman\Panels\Data\Panel;

use Packman\Attributes\Api;
use Packman\Panels\Enums\Panel\Reads;
use Packman\Panels\Enums\Panel\StateRefresh;
use Packman\Panels\Exceptions\Definition\DefinitionException;

/**
 * Effective settings of a compiled panel and where each value came from.
 */
#[Api(since: '1.0')]
final readonly class PanelSettings
{
    public const string RESOURCE_PREFIX = 'resource_prefix';

    public const string CACHE_STORE = 'cache.store';

    public const string CACHE_TTL = 'cache.ttl';

    public const string CACHE_GENERATION = 'cache.generation';

    public const string READS = 'consistency.reads';

    public const string STATE_REFRESH = 'consistency.state_refresh';

    public const string TRACE_DECISIONS = 'trace_decisions';

    /** Values used when neither the panel nor the configuration sets a setting. */
    public const array DEFAULTS = [
        self::RESOURCE_PREFIX => true,
        self::CACHE_STORE => null,
        self::CACHE_TTL => 300,
        self::CACHE_GENERATION => 1,
        self::READS => 'primary',
        self::STATE_REFRESH => 'request',
        self::TRACE_DECISIONS => false,
    ];

    /**
     * @internal built by the panel compiler
     *
     * @param  array<string, string>  $origins  setting name => `provider`, `plugin:<id>`, `configure_all`, `configure` or `default`
     */
    public function __construct(
        private ?string $resourcePrefix,
        private ?string $cacheStore,
        private ?int $cacheTtl,
        private int $cacheGeneration,
        private Reads $reads,
        private StateRefresh $stateRefresh,
        private bool $traceDecisions,
        private array $origins,
    ) {}

    public function resourcePrefix(): ?string
    {
        return $this->resourcePrefix;
    }

    public function cacheStore(): ?string
    {
        return $this->cacheStore;
    }

    public function cacheTtl(): ?int
    {
        return $this->cacheTtl;
    }

    public function cacheGeneration(): int
    {
        return $this->cacheGeneration;
    }

    public function reads(): Reads
    {
        return $this->reads;
    }

    public function stateRefresh(): StateRefresh
    {
        return $this->stateRefresh;
    }

    public function traceDecisions(): bool
    {
        return $this->traceDecisions;
    }

    /**
     * Where the effective value of a setting came from.
     *
     * @throws DefinitionException when the setting does not exist
     */
    public function origin(string $setting): string
    {
        return $this->origins[$setting] ?? throw new DefinitionException(
            'Unknown panel setting "'.$setting.'". Settings: '.implode(', ', array_keys(self::DEFAULTS)).'.',
        );
    }

    /**
     * @return array<string, array{value: bool|int|string|null, origin: string}>
     */
    public function toArray(): array
    {
        $values = [
            self::RESOURCE_PREFIX => $this->resourcePrefix,
            self::CACHE_STORE => $this->cacheStore,
            self::CACHE_TTL => $this->cacheTtl,
            self::CACHE_GENERATION => $this->cacheGeneration,
            self::READS => $this->reads->value,
            self::STATE_REFRESH => $this->stateRefresh->value,
            self::TRACE_DECISIONS => $this->traceDecisions,
        ];

        $settings = [];

        foreach ($values as $setting => $value) {
            $settings[$setting] = ['value' => $value, 'origin' => $this->origins[$setting]];
        }

        return $settings;
    }
}
