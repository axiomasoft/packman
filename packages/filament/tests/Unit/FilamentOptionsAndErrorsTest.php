<?php

declare(strict_types=1);

use Packman\Errors\Internal\CompiledProblemRegistry;
use Packman\Errors\Internal\ProblemContribution;
use Packman\Filament\Concerns\Authorization\AuthorizesPage;
use Packman\Filament\Concerns\Authorization\AuthorizesRelationManager;
use Packman\Filament\Concerns\Authorization\AuthorizesResource;
use Packman\Filament\Concerns\Authorization\AuthorizesWidget;
use Packman\Filament\Enums\FilamentErrorCode;
use Packman\Filament\Exceptions\Binding\InvalidBindingStateException;
use Packman\Filament\Internal\Configuration\FilamentOptions;
use Packman\Filament\Internal\Errors\FilamentProblemDefinitions;
use Packman\Filament\Internal\Reflection\ClassTraits;
use Packman\Filament\Tests\Support\FilamentTestOrderResource;
use Packman\Filament\Tests\Support\FilamentTestOverriddenResource;
use Packman\Filament\Tests\Support\FilamentTestProductsRelationManager;
use Packman\Filament\Tests\Support\FilamentTestReportsPage;
use Packman\Filament\Tests\Support\FilamentTestStatsWidget;
use Packman\Filament\Tests\Support\FilamentTestUnprotectedResource;

it('parses valid FilamentOptions defaults and overrides', function (): void {
    $defaults = FilamentOptions::fromArray([]);

    expect($defaults->navigationGroup)->toBe('Packman')
        ->and($defaults->guardPanel)->toBeNull()
        ->and($defaults->enforce)->toBeFalse()
        ->and($defaults->refuseUnverifiableDownloads)->toBeTrue()
        ->and($defaults->resourceEnabled('roles'))->toBeTrue();

    $custom = FilamentOptions::fromArray([
        'navigation_group' => 'Security',
        'guard_panel' => 'admin',
        'enforce' => true,
        'resources' => ['activity' => false],
        'abilities' => ['view_any', 'custom_action'],
        'exclude' => ['pages' => ['App\Pages\Health']],
        'persistent_middleware' => ['web'],
        'refuse_unverifiable_downloads' => false,
    ]);

    expect($custom->navigationGroup)->toBe('Security')
        ->and($custom->guardPanel)->toBe('admin')
        ->and($custom->enforce)->toBeTrue()
        ->and($custom->resourceEnabled('activity'))->toBeFalse()
        ->and($custom->abilities)->toBe(['view_any', 'custom_action'])
        ->and($custom->exclude['pages'])->toBe(['App\Pages\Health'])
        ->and($custom->persistentMiddleware)->toBe(['web'])
        ->and($custom->refuseUnverifiableDownloads)->toBeFalse();
});

it('rejects malformed FilamentOptions configuration shapes', function (array $bad): void {
    expect(static fn (): FilamentOptions => FilamentOptions::fromArray($bad))
        ->toThrow(InvalidBindingStateException::class);
})->with([
    'empty navigation_group' => [['navigation_group' => '']],
    'empty guard_panel' => [['guard_panel' => '']],
    'non snake ability' => [['abilities' => ['viewAny']]],
    'non array resources' => [['resources' => 'all']],
]);

it('exposes FilamentErrorCode and FilamentProblemDefinitions with filament prefix', function (): void {
    $registry = new CompiledProblemRegistry([
        new ProblemContribution(
            provider: new FilamentProblemDefinitions,
            owner: 'filament',
        ),
    ]);

    foreach (FilamentErrorCode::cases() as $case) {
        expect(str_starts_with($case->value(), 'filament.'))->toBeTrue()
            ->and($registry->get($case)->code)->toBe($case);
    }
});

it('verifies ClassTraits recursive trait usage and un-overridden authorization methods', function (): void {
    expect(ClassTraits::keepsMethods(FilamentTestOrderResource::class, AuthorizesResource::class, ['getAuthorizationResponse', 'getEloquentQuery']))->toBeTrue()
        ->and(ClassTraits::keepsMethods(FilamentTestProductsRelationManager::class, AuthorizesRelationManager::class, ['canViewForRecord', 'getAuthorizationResponse', 'makeTable']))->toBeTrue()
        ->and(ClassTraits::keepsMethods(FilamentTestReportsPage::class, AuthorizesPage::class, ['canAccess']))->toBeTrue()
        ->and(ClassTraits::has(FilamentTestStatsWidget::class, AuthorizesWidget::class))->toBeTrue()
        ->and(ClassTraits::keepsMethods(FilamentTestUnprotectedResource::class, AuthorizesResource::class, ['getAuthorizationResponse', 'getEloquentQuery']))->toBeFalse()
        ->and(ClassTraits::keepsMethods(FilamentTestOverriddenResource::class, AuthorizesResource::class, ['getAuthorizationResponse', 'getEloquentQuery']))->toBeFalse();
});
