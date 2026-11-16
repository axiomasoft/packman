<?php

declare(strict_types=1);

namespace Packman\Events;

use DateTimeImmutable;
use DateTimeZone;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Context\Contracts\ContextScopeInterface;

/**
 * Canonical pure-PHP base for ecosystem domain events (`eventId`, `occurredAt`, `scopeId`, `name()`).
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
abstract readonly class DomainEvent
{
    public string $eventId;

    public DateTimeImmutable $occurredAt;

    public ?string $scopeId;

    public function __construct(
        public ?ContextScopeInterface $scope = null,
        ?string $scopeId = null,
        ?DateTimeImmutable $occurredAt = null,
        ?string $eventId = null,
    ) {
        $this->eventId = $eventId ?? self::generateUuid7();
        $this->occurredAt = $occurredAt ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->scopeId = $scopeId ?? $this->scope?->id();
    }

    /** Stable dotted event name, e.g. `mod.module.enabled`. */
    abstract public static function name(): string;

    private static function generateUuid7(): string
    {
        $timestampMs = (int) (microtime(true) * 1000);
        $random = random_bytes(10);

        $timeHex = str_pad(dechex($timestampMs), 12, '0', STR_PAD_LEFT);
        $unpackedA = unpack('n', substr($random, 0, 2));
        $unpackedB = unpack('n', substr($random, 2, 2));
        $rawA = is_array($unpackedA) && is_int($unpackedA[1] ?? null) ? $unpackedA[1] : 0;
        $rawB = is_array($unpackedB) && is_int($unpackedB[1] ?? null) ? $unpackedB[1] : 0;

        $randA = ($rawA & 0x0FFF) | 0x7000;
        $randBHi = ($rawB & 0x3FFF) | 0x8000;
        $randBLo = bin2hex(substr($random, 4, 6));

        return sprintf(
            '%s-%s-%04x-%04x-%s',
            substr($timeHex, 0, 8),
            substr($timeHex, 8, 4),
            $randA,
            $randBHi,
            $randBLo,
        );
    }
}
