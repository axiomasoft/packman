<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\ClassMethod\LocallyCalledStaticMethodToNonStaticRector;
use Rector\CodeQuality\Rector\If_\ExplicitNullableObjectInstanceRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPublicMethodParameterRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUselessUnionReturnDocblockRector;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\Php81\Rector\Property\ReadOnlyPropertyRector;
use Rector\Php82\Rector\Class_\ReadOnlyClassRector;
use Rector\Php83\Rector\Class_\ReadOnlyAnonymousClassRector;
use Rector\Set\ValueObject\SetList;
use RectorLaravel\Rector\ArrayDimFetch\ServerVariableToRequestFacadeRector;
use RectorLaravel\Rector\FuncCall\UnlinkFuncCallToFileFacadeDeleteRector;
use RectorLaravel\Rector\MethodCall\ContainerBindConcreteWithClosureOnlyRector;
use RectorLaravel\Set\LaravelLevelSetList;
use RectorLaravel\Set\LaravelSetList;

$paths = array_values(array_filter(glob(__DIR__.'/packages/*/src') ?: [], 'is_dir'));

return RectorConfig::configure()
    ->withoutParallel()
    ->withPaths($paths)
    ->withSkip([
        '*/stubs/*',
        '*/Fixtures/*',
        '*/Stubs/*',
        ...glob(__DIR__.'/packages/*/database/migrations', GLOB_ONLYDIR) ?: [],
        ReadOnlyClassRector::class,
        ReadOnlyPropertyRector::class,
        ReadOnlyAnonymousClassRector::class,
        LocallyCalledStaticMethodToNonStaticRector::class,
        UnlinkFuncCallToFileFacadeDeleteRector::class,
        ExplicitNullableObjectInstanceRector::class,
        ContainerBindConcreteWithClosureOnlyRector::class,
        ServerVariableToRequestFacadeRector::class => [
            __DIR__.'/packages/modules/src/Laravel/ModulesBootRegistrar.php',
        ],
        RemoveUnusedPublicMethodParameterRector::class => [
            __DIR__.'/packages/modules/src/Internal/Compiler/CompilationValidator.php',
        ],
        RemoveUselessUnionReturnDocblockRector::class => [
            __DIR__.'/packages/modules/src/Internal/Lifecycle/DatabaseModuleLifecycle.php',
        ],
    ])
    ->withConfiguredRule(ClassPropertyAssignToConstructorPromotionRector::class, [
        ClassPropertyAssignToConstructorPromotionRector::RENAME_PROPERTY => false,
    ])
    ->withPhpSets(php84: true)
    ->withSets([
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
        SetList::EARLY_RETURN,
        SetList::TYPE_DECLARATION,
        LaravelLevelSetList::UP_TO_LARAVEL_120,
        LaravelSetList::LARAVEL_CODE_QUALITY,
        LaravelSetList::LARAVEL_COLLECTION,
    ])
    ->withImportNames(importDocBlockNames: false, removeUnusedImports: true);
