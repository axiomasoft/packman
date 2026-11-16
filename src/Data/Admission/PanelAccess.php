<?php

declare(strict_types=1);

namespace Packman\Panels\Data\Admission;

use InvalidArgumentException;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class PanelAccess
{
    /** @param list<string> $actions */
    public function __construct(public bool $view, public array $actions, public ?string $reason)
    {
        self::validateInput(view: $view, actions: $actions);
    }

    public static function deny(string $reason): self
    {
        return new self(view: false, actions: [], reason: $reason);
    }

    /**
     * Runtime validation also rejects values from untyped integration callers.
     *
     * @param  array<array-key, mixed>  $actions
     */
    private static function validateInput(
        bool $view,
        array $actions,
    ): void {
        foreach ($actions as $action) {
            if (! is_string($action)) {
                throw new InvalidArgumentException('Panel actions must be strings.');
            }
        }

        if ((! $view && $actions !== [])
            || count($actions) !== count(array_unique($actions))
            || array_filter($actions, static fn (string $action): bool => $action === '') !== []) {
            throw new InvalidArgumentException('Panel access actions must be a unique string set.');
        }
    }
}
