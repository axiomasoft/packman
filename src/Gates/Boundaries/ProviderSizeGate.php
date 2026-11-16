<?php

declare(strict_types=1);

namespace Packman\DevTools\Gates\Boundaries;

use Packman\Attributes\Api;
use Packman\DevTools\Contracts\Gates\QualityGate;
use Packman\DevTools\Data\Gates\GateContext;
use Packman\DevTools\Data\Gates\GateResult;
use Packman\DevTools\Internal\Gates\GateFileWalker;

/**
 * Enforces slim package service providers bounded by provider_max_lines in packman.json / profile schemas.
 */
#[Api(since: '1.0')]
final readonly class ProviderSizeGate implements QualityGate
{
    private GateFileWalker $walker;

    public function __construct()
    {
        $this->walker = new GateFileWalker;
    }

    public function id(): string
    {
        return 'provider-size';
    }

    public function category(): string
    {
        return 'boundaries';
    }

    public function description(): string
    {
        return 'Enforces that *ServiceProvider.php files remain thin registrars within provider_max_lines.';
    }

    public function run(GateContext $context): GateResult
    {
        $violations = [];
        $checked = 0;

        foreach ($context->config->packages as $package) {
            $profile = $context->config->profileConfig($package->profile);
            $limit = $profile !== null && is_int($profile['provider_max_lines'] ?? null)
                ? $profile['provider_max_lines']
                : $context->config->providerMaxLines;

            $srcDir = $context->packageDirectory($package).'/src';
            foreach ($this->walker->phpFiles($srcDir) as $file) {
                if (! str_ends_with($file, 'ServiceProvider.php')) {
                    continue;
                }

                $checked++;
                $lines = file($file);
                $lineCount = is_array($lines) ? count($lines) : 0;

                if ($lineCount > $limit) {
                    $rel = $this->walker->relativePath($context->rootPath, $file);
                    $violations[] = sprintf(
                        '[%s] %s has %d lines, exceeding provider_max_lines limit of %d.',
                        $package->name,
                        $rel,
                        $lineCount,
                        $limit,
                    );
                }
            }
        }

        return $violations === []
            ? GateResult::pass($this->id(), $this->category(), [sprintf('All %d service providers stay within <= %d lines.', $checked, $context->config->providerMaxLines)])
            : GateResult::fail($this->id(), $this->category(), $violations);
    }
}
