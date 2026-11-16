<?php

declare(strict_types=1);

namespace Packman\DevTools\Rector;

use Packman\Attributes\Api;
use Packman\DevTools\Rector\Rules\Boundaries\BoundarySafeStringClassRector;
use Packman\DevTools\Rector\Rules\Laravel\DispatchToHelperFunctionsRector;
use Packman\DevTools\Rector\Rules\Laravel\ServerVariableToRequestFacadeRector;
use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;

/**
 * Reusable Packman Rector configuration bundling PHP 8.4 quality sets and boundary-safe custom rules.
 */
#[Api(since: '1.0')]
final class PackmanRectorSet
{
    /** @param list<string> $skipPaths */
    public static function configure(RectorConfig $rectorConfig, array $skipPaths = []): void
    {
        $rectorConfig->sets([
            LevelSetList::UP_TO_PHP_84,
            SetList::CODE_QUALITY,
            SetList::DEAD_CODE,
            SetList::EARLY_RETURN,
            SetList::TYPE_DECLARATION,
        ]);

        $rectorConfig->rules([
            ServerVariableToRequestFacadeRector::class,
            DispatchToHelperFunctionsRector::class,
            BoundarySafeStringClassRector::class,
        ]);

        if ($skipPaths !== []) {
            $rectorConfig->skip($skipPaths);
        }
    }
}
