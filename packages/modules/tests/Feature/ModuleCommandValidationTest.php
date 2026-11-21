<?php

declare(strict_types=1);

use Packman\Modules\Contracts\Lifecycle\ModuleLifecycle;
use Packman\Modules\Data\Lifecycle\ModuleStatus;
use Packman\Modules\Enums\Lifecycle\DisableReason;
use Packman\Modules\Enums\Lifecycle\ModuleState;

it('rejects modules:repair unless the argument is a Composer package name', function (): void {
    $lifecycle = new class implements ModuleLifecycle
    {
        public ?string $repaired = null;

        public function enable(string $module, string $edition = 'standard', bool $cascade = false): void {}

        public function disable(string $module, DisableReason $reason, bool $cascade = false): void {}

        public function uninstall(string $module, bool $keepData = true): void {}

        public function upgrade(string $module): void {}

        public function repair(string $module): void
        {
            $this->repaired = $module;
        }

        public function status(string $module): ModuleStatus
        {
            return new ModuleStatus($module, ModuleState::Available, '1.0.0', null, false, 'standard');
        }
    };
    $this->app->instance(ModuleLifecycle::class, $lifecycle);

    $this->artisan('modules:repair', ['module' => 'not a package'])->assertExitCode(2);
    $this->artisan('modules:repair', ['module' => 'acme/reports'])->assertSuccessful();

    expect($lifecycle->repaired)->toBe('acme/reports');
});
