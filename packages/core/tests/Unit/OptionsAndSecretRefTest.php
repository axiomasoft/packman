<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Packman\Config\Contracts\OptionsFactory;
use Packman\Config\Data\SecretRef;
use Packman\Config\Data\TablePrefix;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Config\Internal\ConfigNormalizer;
use Packman\Config\Internal\ConfigSections;
use Packman\Config\Laravel\ConfigurationValidator;

final readonly class StubSampleOptions implements OptionsFactory
{
    public function __construct(
        public TablePrefix $tablePrefix,
        public ?int $cacheTtl,
    ) {}

    public static function fromArray(array $config, string $path = 'sample'): self
    {
        $validator = new ConfigurationValidator;
        $prefixRaw = $config['table_prefix'] ?? 'pm_';
        $ttl = array_key_exists('cache_ttl', $config) ? $config['cache_ttl'] : 3600;

        if (! is_string($prefixRaw) || preg_match(TablePrefix::PATTERN, $prefixRaw) !== 1) {
            $validator->add("{$path}.table_prefix", 'invalid table prefix');
            $prefix = new TablePrefix('pm_');
        } else {
            $prefix = new TablePrefix($prefixRaw);
        }

        if ($ttl !== null && (! is_int($ttl) || $ttl < 0)) {
            $validator->add("{$path}.cache_ttl", 'must be null or a non-negative integer');
            $ttl = 0;
        }

        $validator->failIfAny();

        return new self($prefix, $ttl);
    }
}

it('validates TablePrefix and OptionsFactory in one pass', function (): void {
    $options = StubSampleOptions::fromArray(['table_prefix' => 'abc_', 'cache_ttl' => null]);

    expect($options->tablePrefix->table('records'))->toBe('abc_records')
        ->and((string) $options->tablePrefix)->toBe('abc_')
        ->and($options->cacheTtl)->toBeNull()
        ->and(fn () => $options->tablePrefix->table('Users'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $options->tablePrefix->table(''))->toThrow(InvalidArgumentException::class);

    try {
        StubSampleOptions::fromArray(['table_prefix' => 'INVALID', 'cache_ttl' => -1]);
        $this->fail('Invalid configuration was accepted.');
    } catch (InvalidConfiguration $exception) {
        expect($exception->violations)->toBe([
            'sample.table_prefix: invalid table prefix',
            'sample.cache_ttl: must be null or a non-negative integer',
        ]);
    }
});

it('redacts SecretRef from string, dump, json, and serialization', function (): void {
    $plaintext = 'super-secret-value';
    $secret = new SecretRef($plaintext);

    expect("prefix {$secret}")->toBe('prefix [redacted]')
        ->and((string) $secret)->toBe('[redacted]')
        ->and($secret->reveal())->toBe($plaintext)
        ->and($secret->isPresent())->toBeTrue();

    ob_start();
    var_dump($secret);
    $dump = ob_get_clean();

    $encoded = json_encode($secret);

    expect($dump)->toBeString()
        ->and($dump)->not->toContain($plaintext)
        ->and($dump)->toContain('[redacted]')
        ->and($encoded)->toBeString()
        ->and($encoded)->not->toContain($plaintext)
        ->and(fn () => serialize($secret))->toThrow(LogicException::class);
});

it('enforces replacement maps in ConfigNormalizer without dot-merging defaults', function (): void {
    $normalizer = new ConfigNormalizer(['pkg.strategies']);
    $repo = new Repository([
        'pkg' => [
            'strategies' => ['custom' => 'CustomHandler'],
        ],
    ]);

    $normalizer->normalize($repo, [
        'pkg' => [
            'mode' => 'strict',
            'strategies' => ['default' => 'DefaultHandler'],
        ],
    ]);

    expect($repo->get('pkg.mode'))->toBe('strict')
        ->and($repo->get('pkg.strategies'))->toBe(['custom' => 'CustomHandler']);

    $missing = new ConfigNormalizer;
    $missing->registerDefaults('pkg', sys_get_temp_dir().'/packman-missing-config-'.bin2hex(random_bytes(4)).'.php');

    expect(fn () => $missing->packageDefaults())->toThrow(InvalidConfiguration::class, 'does not exist');
});

it('seals ConfigSections on read and rejects unknown root sections', function (): void {
    $sections = new ConfigSections;
    $sections->own('core', static function (array $config): void {});

    $sections->assertKnownKeys(['core' => []]);

    expect(fn () => $sections->assertKnownKeys(['unknown' => []]))->toThrow(InvalidConfiguration::class, 'unknown: unknown configuration section.')
        ->and(fn () => $sections->own('late', static function (array $config): void {}))->toThrow(InvalidConfiguration::class, 'registered after the configuration was read');
});
