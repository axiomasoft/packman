<?php

declare(strict_types=1);

namespace Packman\Modules\Contracts\Lifecycle;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Modules\Data\Lifecycle\ModuleStatus;
use Packman\Modules\Enums\Lifecycle\DisableReason;
use Packman\Modules\Exceptions\Lifecycle\HasEnabledDependentsException;
use Packman\Modules\Exceptions\Lifecycle\InvalidStateTransitionException;
use Packman\Modules\Exceptions\Lifecycle\MissingDependencyException;
use Packman\Modules\Exceptions\Lifecycle\ModuleEnableFailedException;

/**
 * State-machine transitions for one module in the current tenant's
 * database: available → enabled → disabled → (archived | purged). `enabled`
 * never goes straight to `archived`/`purged` — disable is a mandatory
 * two-step safety gate. `purged` is terminal: re-installing a purged module
 * goes through {@see repair()}, not enable().
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ModuleLifecycle
{
    /**
     * available|disabled|archived → enabled. All-or-nothing: a failing
     * migration or EnableHook rolls the whole transition back and throws
     * {@see ModuleEnableFailedException} — no partial install. `$cascade`
     * auto-enables the requires chain instead of throwing
     * {@see MissingDependencyException}. Enabling a `purged` module throws
     * {@see InvalidStateTransitionException} — reset it via {@see repair()}
     * first.
     */
    public function enable(string $module, string $edition = 'standard', bool $cascade = false): void;

    /**
     * enabled → disabled. Data is NEVER touched. Throws
     * {@see HasEnabledDependentsException} unless `$cascade` disables
     * dependents first.
     */
    public function disable(string $module, DisableReason $reason, bool $cascade = false): void;

    /**
     * disabled|archived → archived ($keepData=true, tables kept) |
     * purged ($keepData=false, drop by mod_records + PurgeHook). Direct
     * uninstall from `enabled` is rejected — disable first.
     */
    public function uninstall(string $module, bool $keepData = true): void;

    /**
     * Catch-up migrations: dbVersion → codeVersion. Requires `enabled` —
     * throws {@see InvalidStateTransitionException} otherwise.
     */
    public function upgrade(string $module): void;

    /**
     * Reconcile `mod_records` with the real schema (drop claims whose object
     * vanished) and provide the single legal exit from the terminal `purged`
     * state: reset the module to `available` — clearing its ownership rows and
     * migration history — so a fresh enable() re-installs it from scratch.
     * A healthy module keeps its state.
     */
    public function repair(string $module): void;

    public function status(string $module): ModuleStatus;
}
