<?php

declare(strict_types=1);

namespace Packman\Config\Laravel;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Config\Contracts\RuntimeConfigCheck;
use Packman\Config\Exceptions\InvalidConfiguration;

/** Runs the tagged runtime checks before a process accepts work. */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class RuntimeConfigGate
{
    /** Commands that run while an image is built or inspected; they never need runtime requirements. */
    public const array BUILD_COMMANDS = [
        'package:discover',
        'config:cache',
        'config:clear',
        'optimize',
        'optimize:clear',
        'route:cache',
        'route:clear',
        'view:cache',
        'view:clear',
        'event:cache',
        'event:clear',
        'vendor:publish',
        'key:generate',
        'storage:link',
        'list',
        'help',
        'about',
        'packman:config:validate',
        'packman:doctor',
    ];

    /** HTTP: after boot. Console: on command start, except build commands. */
    public static function install(Application $app): void
    {
        if (! $app->runningInConsole()) {
            $app->booted(static function () use ($app): void {
                self::assert($app);
            });

            return;
        }

        $app->make(Dispatcher::class)->listen(CommandStarting::class, static function (CommandStarting $event) use ($app): void {
            if (! in_array($event->command, self::BUILD_COMMANDS, true)) {
                self::assert($app);
            }
        });
    }

    /** @throws InvalidConfiguration with every violation of every check */
    public static function assert(Application $app): void
    {
        $violations = self::violations(app: $app, production: $app->environment('production'));

        if ($violations !== []) {
            throw new InvalidConfiguration($violations);
        }
    }

    /** @return list<string> */
    public static function violations(Application $app, bool $production): array
    {
        $violations = [];

        foreach ($app->tagged(RuntimeConfigCheck::class) as $check) {
            if ($check instanceof RuntimeConfigCheck) {
                array_push($violations, ...$check->violations($production));
            }
        }

        return $violations;
    }
}
