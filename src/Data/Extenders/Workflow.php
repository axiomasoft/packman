<?php

declare(strict_types=1);

namespace Packman\Modules\Data\Extenders;

use Packman\Attributes\Api;
use Packman\Modules\Contracts\Compiler\Extender;

/**
 * 8. Workflow-builder step types and entity trigger events. `stepType`/
 * `trigger` are pure declarations consumed via the host ActionRegistry.
 */
#[Api(since: '0.1.1')]
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

    /** @param  class-string  $stepClass */
    public static function stepType(string $stepClass): self
    {
        $workflow = new self('stepType');
        $workflow->stepClass = $stepClass;

        return $workflow;
    }
}
