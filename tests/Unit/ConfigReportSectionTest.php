<?php

declare(strict_types=1);

namespace Packman\Modules\Tests\Unit;

use Illuminate\Support\Facades\Artisan;
use Packman\Config\Contracts\ConfigReportSectionInterface;
use Packman\Modules\Internal\Support\ModulesConfigSection;
use Packman\Modules\Internal\Support\ModulesOptions;
use Packman\Tests\TestCase;

final class ConfigReportSectionTest extends TestCase
{
    public function test_section_registers_in_report_order_and_exposes_modules_options(): void
    {
        $sections = iterator_to_array($this->app->tagged(ConfigReportSectionInterface::class));
        $owned = array_values(array_filter(
            $sections,
            static fn (ConfigReportSectionInterface $section): bool => $section instanceof ModulesConfigSection,
        ));

        self::assertCount(1, $owned);
        self::assertSame(25, $owned[0]->order());
        self::assertSame([ModulesOptions::class => ['modules']], $owned[0]->options());

        $code = Artisan::call('packman:config:validate');
        self::assertSame(0, $code);
    }
}
