<?php

declare(strict_types=1);

namespace Packman\Modules\Internal\Extend;

use Packman\Modules\Contracts\Extender;

/**
 * 7. Route files (plain Laravel routing), grouped per-module by the
 * activity middleware. `api()` auto-prefixes `/api/{module_short}`.
 *
 * @internal spec: B-10 §4.2
 */
final class Routes implements Extender
{
    /** @var list<string> */
    public array $middlewares = [];

    public ?string $routePrefix = null;

    /** @param 'api'|'web' $kind */
    private function __construct(public string $kind, public string $file) {}

    public static function api(string $file): self
    {
        return new self(kind: 'api', file: $file);
    }

    /** @param  list<string>  $mw */
    public function middleware(array $mw): self
    {
        $this->middlewares = $mw;

        return $this;
    }

    public function prefix(string $prefix): self
    {
        $this->routePrefix = $prefix;

        return $this;
    }
}
