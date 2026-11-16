<?php

declare(strict_types=1);

namespace Packman\Modules\Internal\Extend;

use Packman\Modules\Contracts\Compiler\Extender;

/**
 * 8. Workflow-builder step types and entity trigger events. `stepType`/
 * `trigger` are pure declarations consumed via the host ActionRegistry.
 *
 * @internal spec: B-10 §4.2, B-12
 */
final class Workflow implements Extender
{
    /** @var class-string|null */
    public ?string $stepClass = null;

    public ?string $entityHandle = null;

    /** @var list<string> */
    public array $eventNames = [];

    /** @param 'stepType'|'trigger' $kind */
    private function __construct(public string $kind) {}

    /** @param  list<string>  $eventNames */
    public static function trigger(string $entityHandle, array $eventNames): self
    {
        $workflow = new self('trigger');
        $workflow->entityHandle = $entityHandle;
        $workflow->eventNames = $eventNames;

        return $workflow;
    }
}
