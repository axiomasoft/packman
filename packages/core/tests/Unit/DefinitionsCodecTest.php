<?php

declare(strict_types=1);

use Packman\Config\Data\SecretRef;
use Packman\Config\Internal\DefinitionsCodec;

enum SampleCodecMode: string
{
    case Flat = 'flat';
}

final readonly class SampleCodecValue
{
    /** @param array<string, mixed> $nested */
    public function __construct(public SampleCodecMode $mode, public array $nested) {}
}

final class SampleCodecLeaky
{
    /** @var array<string, mixed> */
    public array $cache = [];

    public function __construct(public int $a) {}
}

it('round trips nested objects, enums and marker-like map keys losslessly', function (): void {
    $codec = new DefinitionsCodec;
    $value = new SampleCodecValue(SampleCodecMode::Flat, ['@c' => ['@a' => null], 'nested' => new SampleCodecValue(SampleCodecMode::Flat, [])]);
    $encoded = $codec->encode($value);

    expect($codec->decode($encoded))->toEqual($value)
        ->and(var_export($encoded, true))->not->toContain('::__set_state');
});

it('refuses object state outside promoted constructor parameters', function (): void {
    (new DefinitionsCodec)->encode(new SampleCodecLeaky(1));
})->throws(LogicException::class, 'SampleCodecLeaky::$cache is not a constructor parameter');

it('refuses nested closures instead of losing executable state', function (): void {
    (new DefinitionsCodec)->encode(['closure' => static fn (): int => 1]);
})->throws(LogicException::class, 'cannot cache a value of type Closure');

it('refuses SecretRef wrappers before reflecting private properties', function (): void {
    (new DefinitionsCodec)->encode(['secret' => new SecretRef('top-secret')]);
})->throws(LogicException::class, 'SecretRef must not be cached');

it('rejects malformed cache envelopes so callers can recompile rather than hydrate invalid data', function (array $encoded): void {
    expect(fn () => (new DefinitionsCodec)->decode($encoded))->toThrow(LogicException::class);
})->with([
    'unknown enum' => [['@e' => 'MissingEnum', 'v' => 'flat']],
    'structured backing value' => [['@e' => SampleCodecMode::class, 'v' => []]],
    'non-array payload' => [['@a' => false]],
    'unknown object' => [['@c' => 'MissingObject', 'a' => []]],
    'non-array arguments' => [['@c' => SampleCodecValue::class, 'a' => null]],
]);
