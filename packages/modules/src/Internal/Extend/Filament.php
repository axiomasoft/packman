<?php

declare(strict_types=1);

namespace Packman\Modules\Internal\Extend;

use Packman\Modules\Contracts\Compiler\Extender;

/**
 * 4. Filament resources/pages/widgets, or a tab bolted onto ANOTHER
 * module's entity resource — bridges into packman-filament's Plugin Registry.
 *
 * @internal spec: B-10 §4.2
 */
final class Filament implements Extender
{
    /** @var 'resources'|'pages'|'widgets'|'tab' */
    public string $kind;

    public string $panel;

    /** @var list<class-string> */
    public array $classes = [];

    public ?string $entityHandle = null;

    private function __construct()
    {
        //
    }

    /** @param  list<class-string>  $resources */
    public static function resources(array $resources, string $panel = 'app'): self
    {
        $filament = new self;
        $filament->kind = 'resources';
        $filament->classes = $resources;
        $filament->panel = $panel;

        return $filament;
    }
}
