<?php

declare(strict_types=1);

namespace Fixture\StandalonePhp;

use Packman\Config\Contracts\OptionsFactory;
use Packman\Config\Data\SecretRef;
use Packman\Config\Data\TablePrefix;
use Packman\Config\Exceptions\InvalidConfiguration;

final readonly class ConsumerOptions implements OptionsFactory
{
    public function __construct(
        public TablePrefix $tablePrefix,
        public SecretRef $apiToken,
        public int $timeoutSeconds,
    ) {}

    /** @param array<array-key, mixed> $config */
    public static function fromArray(array $config, string $path = 'consumer'): self
    {
        $violations = [];

        $rawPrefix = $config['table_prefix'] ?? 'csm_';
        if (! is_string($rawPrefix) || preg_match(TablePrefix::PATTERN, $rawPrefix) !== 1) {
            $violations[] = "{$path}.table_prefix: must match ".TablePrefix::PATTERN;
            $prefix = new TablePrefix('csm_');
        } else {
            $prefix = new TablePrefix($rawPrefix);
        }

        $rawToken = $config['api_token'] ?? 'default-token';
        if (! is_string($rawToken) || $rawToken === '') {
            $violations[] = "{$path}.api_token: must be a non-empty string";
            $token = new SecretRef('invalid');
        } else {
            $token = new SecretRef($rawToken);
        }

        $timeout = $config['timeout_seconds'] ?? 30;
        if (! is_int($timeout) || $timeout < 1 || $timeout > 300) {
            $violations[] = "{$path}.timeout_seconds: must be between 1 and 300";
            $timeout = 30;
        }

        if ($violations !== []) {
            throw InvalidConfiguration::fromProblems($violations);
        }

        return new self($prefix, $token, $timeout);
    }
}
