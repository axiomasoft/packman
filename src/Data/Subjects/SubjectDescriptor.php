<?php

declare(strict_types=1);

namespace Packman\Panels\Data\Subjects;

use Illuminate\Database\Eloquent\Model;
use Packman\Attributes\Api;

/**
 * A compiled subject of a panel as `PanelBuilder::for()` declared it.
 */
#[Api(since: '1.0')]
final readonly class SubjectDescriptor
{
    /**
     * @internal built by the panel compiler
     *
     * @param  class-string<Model>  $model
     */
    public function __construct(
        public string $model,
        public ?string $guard = null,
        public ?string $directory = null,
    ) {}
}
