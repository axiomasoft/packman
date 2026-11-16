<?php

declare(strict_types=1);

namespace Packman\DevTools\Console;

use Packman\Attributes\Api;
use Packman\DevTools\Commands\Contracts\ConfigSchemaCommand;
use Packman\DevTools\Commands\Contracts\ErrorCodesCommand;
use Packman\DevTools\Commands\Contracts\PhpApiCommand;
use Packman\DevTools\Commands\Distribution\SplitCommand;
use Packman\DevTools\Commands\Gates\GateRunnerCommand;
use Packman\DevTools\Commands\Make\MakeCommand;
use Packman\DevTools\Commands\Sync\AiSyncCommand;
use Symfony\Component\Console\Application;

/** Wires the Symfony Console Application for the bin/packman engineering CLI. */
#[Api(since: '1.0')]
final class ApplicationFactory
{
    public static function create(?string $rootPath = null): Application
    {
        $resolvedRoot = $rootPath ?? self::detectRootPath();
        $application = new Application('Packman DevTools', '1.0.0');

        $application->addCommands([
            new GateRunnerCommand($resolvedRoot),
            new PhpApiCommand($resolvedRoot),
            new ErrorCodesCommand($resolvedRoot),
            new ConfigSchemaCommand($resolvedRoot),
            new MakeCommand($resolvedRoot),
            new SplitCommand($resolvedRoot),
            new AiSyncCommand($resolvedRoot),
        ]);

        return $application;
    }

    public static function detectRootPath(): string
    {
        $cwd = getcwd();

        if (is_string($cwd) && is_file($cwd.'/packman.json')) {
            return $cwd;
        }

        return dirname(__DIR__, 4);
    }
}
