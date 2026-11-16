<?php

declare(strict_types=1);

namespace Packman\Modules\Tests\PHPStan\Data\Billing;

final class FsOutsideModuleFixture
{
    public function violation(): void
    {
        file_put_contents('/etc/flex/invoice.log', 'entry');
    }

    public function traversalViolation(): string|false
    {
        return file_get_contents('../../.env');
    }

    public function legal(): string|false
    {
        return file_get_contents(__DIR__.'/invoice.stub');
    }

    public function computedPathIsNotDecidable(string $path): string|false
    {
        return file_get_contents($path);
    }
}
