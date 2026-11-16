<?php

declare(strict_types=1);

namespace Fixture\StandaloneLaravel;

use Packman\Config\Contracts\OptionsFactory;
use Packman\Config\Laravel\ConfigurationValidator;

final readonly class BillingOptions implements OptionsFactory
{
    public function __construct(
        public string $currency,
        public bool $strictRounding,
    ) {}

    /** @param array<array-key, mixed> $config */
    public static function fromArray(array $config, string $path = 'billing'): self
    {
        $validator = new ConfigurationValidator;

        $currency = $config['currency'] ?? 'USD';
        if (! is_string($currency) || preg_match('/\A[A-Z]{3}\z/', $currency) !== 1) {
            $validator->add("{$path}.currency", 'must be a 3-letter ISO currency code');
            $currency = 'USD';
        }

        $strict = $config['strict_rounding'] ?? true;
        if (! is_bool($strict)) {
            $validator->add("{$path}.strict_rounding", 'must be a boolean');
            $strict = true;
        }

        $validator->failIfAny();

        return new self($currency, $strict);
    }
}
