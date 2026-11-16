<?php

declare(strict_types=1);

namespace Packman\Config\Data;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * The application facts configuration compilation may depend on besides raw config arrays.
 * Every field participates in the definitions cache fingerprint.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final readonly class CompileContext
{
    /**
     * @param  list<string>  $connections
     * @param  array<string, string>  $connectionIdentities
     * @param  array<string, string>  $connectionPrefixes
     * @param  array<string, mixed>  $extras
     */
    public function __construct(
        public string $environment,
        public string $defaultConnection,
        public array $connections,
        public ?string $userModelFallback,
        public bool $allowUnsafe,
        public array $connectionIdentities = [],
        public array $connectionPrefixes = [],
        public string $appName = 'Laravel',
        public array $extras = [],
    ) {}

    public function isProduction(): bool
    {
        return $this->environment === 'production';
    }

    /**
     * @return array{
     *     environment: string,
     *     defaultConnection: string,
     *     connections: list<string>,
     *     userModelFallback: string|null,
     *     allowUnsafe: bool,
     *     connectionIdentities: array<string, string>,
     *     connectionPrefixes: array<string, string>,
     *     appName: string,
     *     extras: array<string, mixed>,
     * }
     */
    public function toArray(): array
    {
        return [
            'environment' => $this->environment,
            'defaultConnection' => $this->defaultConnection,
            'connections' => $this->connections,
            'userModelFallback' => $this->userModelFallback,
            'allowUnsafe' => $this->allowUnsafe,
            'connectionIdentities' => $this->connectionIdentities,
            'connectionPrefixes' => $this->connectionPrefixes,
            'appName' => $this->appName,
            'extras' => $this->extras,
        ];
    }
}
