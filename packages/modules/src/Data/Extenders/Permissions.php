<?php

declare(strict_types=1);

namespace Packman\Modules\Data\Extenders;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Modules\Contracts\Compiler\Extender;

/**
 * 6. Authorization permission group `{panel}.{entity}.{action}`, claimed into
 * mod_records on enable. `scopeable()` opts into the role-matrix scope
 * axis (none/own/dept/dept_tree/workspace/account).
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class Permissions implements Extender
{
    public bool $isScopeable = false;

    /** @param  list<string>  $actions  private constructor — build via group(). */
    private function __construct(public string $entity, public array $actions, public string $panel) {}

    /** @param  list<string>  $actions */
    public static function group(string $entity, array $actions, string $panel = 'app'): self
    {
        return new self(entity: $entity, actions: $actions, panel: $panel);
    }

    public function scopeable(): self
    {
        $this->isScopeable = true;

        return $this;
    }
}
