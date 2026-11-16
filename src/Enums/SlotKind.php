<?php

declare(strict_types=1);

namespace Packman\Plugins\Enums;

use Packman\Attributes\Api;

/**
 * Behavioral contract of an extension slot.
 */
#[Api(since: '1.0')]
enum SlotKind: string
{
    case Guard = 'guard';
    case Restriction = 'restriction';
    case Contributor = 'contributor';
    case Handler = 'handler';
    case EventSink = 'event-sink';
    case OutboxConsumer = 'outbox-consumer';
    case OperationMiddleware = 'operation-middleware';
    case PipelineStage = 'pipeline-stage';
}
