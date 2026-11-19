<?php

declare(strict_types=1);

namespace Packman\Modules\Data\Extenders;

use Packman\Attributes\Api;
use Packman\Modules\Contracts\Compiler\Extender;

/**
 * 5. Panel navigation entry or group. Order between siblings is
 * deterministic via `after()` — never a numeric priority.
 */
#[Api(since: '0.1.1')]
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

    public static function group(string $panel, string $handle, string $label): self
    {
        $menu = new self($panel, $handle);
        $menu->isGroup = true;
        $menu->label = $label;

        return $menu;
    }

    public function route(string $routeName): self
    {
        $this->routeName = $routeName;

        return $this;
    }

    /** @param  class-string  $badgeResolverClass */
    public function badge(string $badgeResolverClass): self
    {
        $this->badgeResolverClass = $badgeResolverClass;

        return $this;
    }
}
