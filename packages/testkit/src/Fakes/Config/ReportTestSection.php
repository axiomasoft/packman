<?php

declare(strict_types=1);

namespace Packman\Testkit\Fakes\Config;

use Packman\Attributes\Api;
use Packman\Config\Contracts\ConfigReader;
use Packman\Config\Contracts\ConfigSection;

/** Reusable test fixture section implementing Packman ConfigSection for section compiler tests. */
#[Api(since: '1.0')]
final readonly class ReportTestSection implements ConfigSection
{
    public function __construct(
        private string $sectionKey = 'report_test',
        private bool $strict = true,
    ) {}

    public function key(): string
    {
        return $this->sectionKey;
    }

    public function settingsClass(): string
    {
        return ReportTestSettings::class;
    }

    public function compile(ConfigReader $reader): ReportTestSettings
    {
        return new ReportTestSettings(
            enabled: $reader->bool('enabled', true, doc: 'Enables fixture reporting section.'),
            channel: $reader->string('channel', $this->strict ? 'strict' : 'default', doc: 'Reporting channel identifier.'),
        );
    }
}
