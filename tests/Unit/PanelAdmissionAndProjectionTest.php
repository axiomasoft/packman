<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Packman\Context\Contracts\ContextScopeInterface;
use Packman\Context\Data\ScopeRef;
use Packman\Panels\Builder\Panel;
use Packman\Panels\Builder\PanelBuilder;
use Packman\Panels\Contracts\Admission\PanelAdmissionGate;
use Packman\Panels\Contracts\Admission\PanelPolicyResolver;
use Packman\Panels\Contracts\Subjects\PanelSubjectResolver;
use Packman\Panels\Data\Panel\PanelFormData;
use Packman\Panels\Data\Panel\PanelSlot;
use Packman\Panels\Data\Subjects\SubjectChain;
use Packman\Panels\Data\Subjects\SubjectNode;
use Packman\Panels\Enums\Admission\AssignmentScopeMode;
use Packman\Panels\Enums\Admission\TenantMode;
use Packman\Panels\Exceptions\Permissions\PolicyConflictException;
use Packman\Panels\Exceptions\Subjects\SubjectResolutionFailed;
use Packman\Panels\Internal\Admission\PanelAdmission;
use Packman\Panels\Internal\Catalogue\PanelCompiler;
use Packman\Panels\Internal\Catalogue\PanelRecipe;
use Packman\Panels\Internal\Subjects\PanelFormProjection;
use Packman\Panels\Internal\Subjects\PanelSubjects;

class AdmissionTestUser extends Model {}

function compileAdmissionPanel(Closure $setup): Panel
{
    $recipe = new PanelRecipe('admin');
    $setup(new PanelBuilder($recipe));
    $recipe->seal();

    return (new PanelCompiler)->compile($recipe);
}

it('admits through graceful child scope role witness and enforces tenant & entry permissions', function (): void {
    $panel = compileAdmissionPanel(static fn (PanelBuilder $b): PanelBuilder => $b
        ->for(AdmissionTestUser::class)
        ->tenantMode(TenantMode::Required)
        ->entry('panel.enter'));

    $gate = new class implements PanelAdmissionGate
    {
        public bool $member = true;

        public bool $hasRole = false;

        /** @var list<string> */
        public array $childScopes = ['team-1'];

        public bool $entryAllowed = true;

        public function isMember(Panel $panel, object $subject, ContextScopeInterface $tenant, ?string $scopeType = null, ?string $scopeId = null): bool
        {
            return $this->member;
        }

        public function hasRole(Panel $panel, object $subject, ContextScopeInterface $tenant, ?string $scopeType = null, ?string $scopeId = null): bool
        {
            if ($scopeType === 'team' && $scopeId === 'team-1') {
                return true;
            }

            return $this->hasRole;
        }

        public function childScopesWithRole(Panel $panel, object $subject, ContextScopeInterface $tenant, string $scopeType): array
        {
            return $scopeType === 'team' ? $this->childScopes : [];
        }

        public function allowsEntry(Panel $panel, object $subject, ContextScopeInterface $tenant, string|UnitEnum $permission, ?string $scopeType = null, ?string $scopeId = null): bool
        {
            return $this->entryAllowed;
        }
    };

    $panelWithScopes = compileAdmissionPanel(static fn (PanelBuilder $b): PanelBuilder => $b
        ->for(AdmissionTestUser::class)
        ->tenantMode(TenantMode::Required)
        ->scopeMode(AssignmentScopeMode::Inherit)
        ->scopeTypes(['team'])
        ->entry('panel.enter'));

    $admission = new PanelAdmission($gate);
    $user = new AdmissionTestUser;

    expect($admission->admit($panelWithScopes, $user, new ScopeRef('global')))->toBeNull();

    $witnessed = $admission->admit($panelWithScopes, $user, new ScopeRef('tenant-1'));
    expect($witnessed)->not->toBeNull()
        ->and($witnessed?->scopedWitness)->toBeTrue()
        ->and($witnessed?->isGlobal())->toBeTrue();

    $gate->entryAllowed = false;
    expect($admission->admit($panel, $user, new ScopeRef('tenant-1')))->toBeNull();
});

it('rejects spoofed ancestry chains and orphan subjects in PanelSubjects', function (): void {
    $tenant = new SubjectNode('t-1', 'tenant', 't-1');
    $orgFlat = new SubjectNode('t-1', 'org', 'o-1');
    $user = new SubjectNode('t-1', 'user', 'u-1', [$tenant, $orgFlat]);

    $chains = [
        $tenant->key() => new SubjectChain($tenant),
        $orgFlat->key() => new SubjectChain($orgFlat, [$tenant]),
        $user->withoutParents()->key() => new SubjectChain($user->withoutParents(), [$tenant, $orgFlat]),
    ];

    $resolver = new readonly class($chains) implements PanelSubjectResolver
    {
        /** @param array<string, SubjectChain> $chains */
        public function __construct(private array $chains) {}

        public function resolve(SubjectNode $subject): ?SubjectChain
        {
            return $this->chains[$subject->key()] ?? null;
        }
    };

    $subjects = new PanelSubjects($resolver, ['tenant', 'org', 'user']);

    expect($subjects->resolve($user)->leaf->equals($user->withoutParents()))->toBeTrue();

    $spoofed = new SubjectNode('t-1', 'user', 'u-1', [new SubjectNode('t-1', 'org', 'wrong')]);
    expect(fn () => $subjects->resolve($spoofed))->toThrow(SubjectResolutionFailed::class, 'Caller ancestry conflicts');

    $unregistered = new SubjectNode('t-1', 'rogue', 'r-1');
    expect(fn () => $subjects->resolve($unregistered))->toThrow(SubjectResolutionFailed::class, 'Unregistered subject type');

    $tamperedChain = new SubjectChain($user->withoutParents(), [$tenant]);
    expect(fn () => $subjects->verify($tamperedChain))->toThrow(SubjectResolutionFailed::class, 'differs');
});

it('clamps bounds in PanelFormProjection and detects editVersion race conflicts', function (): void {
    $panel = compileAdmissionPanel(static fn (PanelBuilder $b): PanelBuilder => $b
        ->slots([new PanelSlot('read', ['timeout.ms', 'secret.token'], ['timeout.ms' => 50, 'secret.token' => 'hidden'])]));

    $tenant = new SubjectNode('t-1', 'tenant', 't-1');
    $resolver = new readonly class($tenant) implements PanelSubjectResolver
    {
        public function __construct(private SubjectNode $tenant) {}

        public function resolve(SubjectNode $subject): ?SubjectChain
        {
            return $subject->key() === $this->tenant->key() ? new SubjectChain($this->tenant) : null;
        }
    };

    $policies = new class implements PanelPolicyResolver
    {
        public int $versionCall = 0;

        public bool $simulateRace = false;

        public function canView(SubjectChain $chain, string $panelKey, string $slotKey): bool
        {
            return true;
        }

        public function canUpdate(SubjectChain $chain, string $panelKey, string $slotKey): bool
        {
            return true;
        }

        public function editVersion(string $tenantId): string
        {
            $this->versionCall++;

            return $this->simulateRace ? 'v-'.$this->versionCall : 'v-1';
        }

        public function findBinding(SubjectChain $chain, string $panelKey, string $slotKey): ?array
        {
            return ['revision' => 3, 'retired' => false, 'patch' => ['changes' => []]];
        }

        public function resolveFields(SubjectChain $chain, PanelSlot $slot, string $panelKey): array
        {
            return [
                'timeout.ms' => [
                    'value' => 9999,
                    'inherited' => false,
                    'source' => 'binding@r3',
                    'revision' => 3,
                    'minimum' => 100,
                    'maximum' => 1000,
                    'clamped_by' => 'org:1',
                    'clamped_revision' => 2,
                    'editable' => true,
                    'sensitive' => false,
                ],
                'secret.token' => [
                    'value' => 'secret',
                    'sensitive' => true,
                ],
            ];
        }
    };

    $projector = new PanelFormProjection(new PanelSubjects($resolver), $policies);
    $form = $projector->project($panel, $tenant, 'read');

    expect($form)->toBeInstanceOf(PanelFormData::class)
        ->and($form->editVersion)->toBe('v-1')
        ->and($form->fields)->toHaveKey('timeout.ms')
        ->and($form->fields)->not->toHaveKey('secret.token')
        ->and($form->fields['timeout.ms']['value'])->toBe(1000)
        ->and($form->fields['timeout.ms']['clamped'])->toBeTrue()
        ->and($form->fields['timeout.ms']['clamped_by'])->toBe('org:1');

    $policies->simulateRace = true;
    expect(fn () => $projector->project($panel, $tenant, 'read'))->toThrow(PolicyConflictException::class);
});
