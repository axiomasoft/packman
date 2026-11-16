<?php

declare(strict_types=1);

namespace Packman\Modules\Internal\Extend;

use Packman\Modules\Contracts\Extender;

/**
 * 5. Panel navigation entry or group. Order between siblings is
 * deterministic via `after()` — never a numeric priority.
 *
 * @internal spec: B-10 §4.2
 */
final class Menu implements Extender
{
    public bool $isGroup = false;

    public ?string $label = null;

    public ?string $icon = null;

    public ?string $routeName = null;

    public ?string $afterHandle = null;

    public ?string $permission = null;

    /** @var class-string|null */
    public ?string $badgeResolverClass = null;

    private function __construct(public string $panel, public string $handle) {}

    public static function item(string $panel, string $handle): self
    {
        return new self(panel: $panel, handle: $handle);
    }

    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function icon(string $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    public function after(string $siblingHandle): self
    {
        $this->afterHandle = $siblingHandle;

        return $this;
    }

    public function permission(string $permission): self
    {
        $this->permission = $permission;

        return $this;
    }
}
