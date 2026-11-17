<?php

declare(strict_types=1);

namespace Packman\Plugins\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Packman\Attributes\Internal;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Plugins\Contracts\Registry\PluginInspector;

#[Internal]
final class ExtensionsCommand extends Command
{
    protected $signature = 'packman:plugins {--json : Print machine-readable JSON}';

    protected $description = 'List the compiled Packman plugins, their contribution kinds, and keys.';

    public function handle(Container $container): int
    {
        try {
            $plugins = $container->make(PluginInspector::class)->describe();
        } catch (InvalidConfiguration $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->line(json_encode($plugins, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($plugins as $plugin) {
            foreach ($plugin->kinds as $kind) {
                $rows[] = [$plugin->id, $plugin->version, $plugin->source->value, $kind->name, '(kind)', $kind->interface];
            }

            foreach ($plugin->contributions as $contribution) {
                $rows[] = [$plugin->id, $plugin->version, $plugin->source->value, $contribution->kind, $contribution->key, $contribution->class];
            }

            if ($plugin->kinds === [] && $plugin->contributions === []) {
                $rows[] = [$plugin->id, $plugin->version, $plugin->source->value, '-', '-', $plugin->class];
            }
        }

        $this->table(['Plugin', 'Version', 'Source', 'Kind', 'Key', 'Class'], $rows);

        return self::SUCCESS;
    }
}
